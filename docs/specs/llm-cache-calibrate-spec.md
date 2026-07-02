# Spec: llm-cache:calibrate

Offline calibration tool for `yegoragapov/laravel-llm-cache`. Answers the one
question the runtime `stats` command cannot: **is the similarity threshold
correct on MY data, and what does a wrong choice cost?**

Runtime `stats` measures hit-rate (savings). It is blind to correctness — a high
hit-rate is indistinguishable from "cheaply serving wrong answers." Calibration
closes that blind spot BEFORE production, offline, against a labeled dataset.

---

## 1. Goal

Given a labeled dataset of prompt pairs (each pair marked "should share a cached
answer" or "must not"), sweep the similarity threshold across a range and report,
per threshold, how many **false hits** (served a cached answer to a genuinely
different question) and **false misses** (regenerated when reuse was correct) the
cache would produce. Let the developer choose a threshold from evidence — a
false-hit/false-miss matrix on their own data — instead of the blind default
(0.95).

This is a dev-time artisan command. It performs no LLM generation and mutates no
cache; it only embeds dataset prompts and computes similarities.

---

## 2. Scope

**In scope**
- Artisan command `llm-cache:calibrate {dataset} {--min=} {--max=} {--step=} {--json} {--provider=}`.
- Dataset loader for a documented file format (labeled prompt pairs).
- Threshold sweep using the package's configured `EmbeddingProvider`.
- Per-threshold confusion metrics: false hits, false misses, precision, recall,
  F1 of the "reuse" decision.
- Human table output + `--json` for CI/scripting.
- A recommended threshold (max F1, or a tunable objective) with the caveat that
  the developer owns the final trade-off.
- Embedding cache within a run (each distinct prompt embedded once, reused across
  all thresholds and pairs).

**Out of scope**
- The LLM-based verification layer itself (separate v2 "trust layer" work). This
  command *quantifies* the need for it but does not implement it.
- Any runtime dashboard / visualization (separate companion package later).
- Auto-applying the recommended threshold to config (prints it; developer edits).
- Adaptive/online threshold tuning (§11 future of the main spec).
- Generating or labeling the dataset (developer supplies it).
- Multi-turn context calibration (context is exact-matched by design; only the
  semantic prompt match is calibrated here).

---

## 3. Data model

No database tables. Input is a file; output is a report.

### 3.1 Dataset file format (JSONL, one pair per line)

```json
{"a": "what are your opening hours?", "b": "when do you open?", "reuse": true}
{"a": "how do I cancel my subscription?", "b": "how do I renew my subscription?", "reuse": false}
{"a": "is it safe during pregnancy?", "b": "is it dangerous during pregnancy?", "reuse": false}
```

- `a`, `b`: the two prompts (string, required, non-empty).
- `reuse` (bool, required): ground truth. `true` = a cache hit of A's answer for
  B is CORRECT. `false` = serving A's answer for B is a FALSE HIT (the dangerous
  case — near-identical vectors, opposite intent).
- Optional `note` (string): free-text, ignored by logic, echoed in verbose output
  for auditing which pairs flip at which threshold.

Rationale: the hard negatives (negation, numeric, opposite-intent pairs the PM
review flagged) are exactly the rows the developer must hand-author. The format
makes that explicit and cheap.

### 3.2 In-memory result shape

```
CalibrationResult
  thresholds: array<ThresholdRow>
  recommended: float
  recommendedObjective: string   // e.g. "cost_weighted"
  datasetSize: int
  positives: int                 // count reuse=true
  negatives: int                 // count reuse=false

ThresholdRow
  threshold: float
  falseHits: int        // reuse=false but similarity >= threshold  (served wrong)
  falseMisses: int      // reuse=true  but similarity <  threshold  (missed reuse)
  truePositives: int    // reuse=true  and similarity >= threshold
  trueNegatives: int    // reuse=false and similarity <  threshold
  precision: float      // TP / (TP + falseHits)
  recall: float         // TP / (TP + falseMisses)
  f1: float
  cost: float           // falseHitPenalty·falseHits + falseMisses (cost_weighted)
```

---

## 4. Contracts

### 4.1 Command signature

```
php artisan llm-cache:calibrate {dataset}
    {--min=0.80}      # sweep start (inclusive)
    {--max=0.99}      # sweep end (inclusive)
    {--step=0.01}     # sweep increment
    {--provider=}     # override configured embedding provider by name
    {--objective=cost_weighted}  # cost_weighted (default) | max_precision | min_false_hits | max_f1
    {--false-hit-penalty=5}      # weight of a false hit vs a false miss for cost_weighted
    {--strict=true}   # abort on malformed dataset line; false = skip with warning
    {--json}          # emit JSON instead of table
```

The default objective is `cost_weighted`, not `max_f1`. This tool exists to
expose the **asymmetry** the PM review named: a false hit is a confident lie, a
false miss is only a wasted regeneration. F1 weighs them symmetrically, which
would make the tool contradict its own thesis. `cost_weighted` minimizes
`penalty·false_hits + false_misses` (penalty default 5), so the recommended
threshold reflects that a false hit hurts more. `max_f1` remains available for
callers who accept symmetric cost.

### 4.2 Internal service (testable without the console)

```php
final class Calibrator
{
    public function __construct(private EmbeddingProvider $provider) {}

    /** @param iterable<PromptPair> $pairs */
    public function run(
        iterable $pairs,
        float $min,
        float $max,
        float $step,
        string $objective = 'cost_weighted',
        float $falseHitPenalty = 5.0,
    ): CalibrationResult;
}
```

- The command is a thin wrapper: load file → build `Calibrator` from the
  resolved provider → `run()` → render. All logic lives in `Calibrator` so it is
  unit-testable with the `null` provider.
- Each distinct prompt string is embedded exactly once per run (memoized), then
  cosine similarity per pair is computed once and reused across every threshold
  in the sweep. Embedding is the only cost; the sweep itself is pure arithmetic.

### 4.3 Output — table (default)

```
Dataset: fixtures/pairs.jsonl  (128 pairs: 74 reuse, 54 no-reuse)
Provider: voyage (dim 1024)

 threshold  false_hits  false_misses  precision  recall    f1     cost*
 0.90       11          3             0.869      0.959     0.912   58
 0.93        6          7             0.918      0.905     0.911   37
 0.95        2         14             0.973      0.811     0.884   24
 0.97        0         22             1.000      0.703     0.826   22
 ...
 * cost = 5·false_hits + false_misses  (--false-hit-penalty=5)

Recommended (cost_weighted, penalty=5): 0.97
  false_hits at 0.97: 0  false_misses: 22  weighted cost: 22

WARNING: false_hits > 0 at every threshold below 0.97. On this dataset the
lowest-cost choice under the default penalty pushes the threshold UP to buy
zero false hits, at the price of 22 false misses (30% of correct reuse). Lower
the penalty if wasted regeneration matters more to you than a wrong answer; a
verification layer would let you sit lower with fewer false hits either way.
Pairwise numbers are an optimistic bound — production 1-NN over a full cache
will not do better than this.
```

### 4.4 Output — `--json`

```json
{
  "dataset_size": 128,
  "positives": 74,
  "negatives": 54,
  "provider": "voyage",
  "recommended": 0.97,
  "objective": "cost_weighted",
  "false_hit_penalty": 5,
  "thresholds": [
    {"threshold":0.90,"false_hits":11,"false_misses":3,"precision":0.869,"recall":0.959,"f1":0.912,"cost":58},
    ...
  ],
  "false_hit_pairs": {
    "0.93": [
      {"a":"how do I cancel...","b":"how do I renew...","similarity":0.942,"note":"negation"}
    ]
  }
}
```

`false_hit_pairs` lists the offending pairs per threshold so the developer can
see *which* semantics collapse — the actionable output, not just the count.

---

## 5. Acceptance criteria

### 5.1 Behavioral (null provider, deterministic)

```
Given  a dataset of 3 pairs with known embeddings (null/fake provider seeded so
       similarities are deterministic)
When   calibrate runs with min=0.80 max=0.99 step=0.01
Then   each ThresholdRow's false_hits = count(reuse=false AND sim >= threshold),
       false_misses = count(reuse=true AND sim < threshold), verified exactly.

Given  a pair with reuse=false whose similarity is 0.94
When   the sweep reaches threshold 0.93
Then   that pair is counted a false_hit; at threshold 0.95 it is a true_negative.

Given  a pair with reuse=true whose similarity is 0.92
When   the sweep reaches threshold 0.95
Then   that pair is counted a false_miss; at threshold 0.90 it is a true_positive.

Given  the default objective (cost_weighted) with false-hit-penalty=5
When   threshold X has 2 false_hits + 4 false_misses (cost 2·5+4=14) and
       threshold Y has 0 false_hits + 20 false_misses (cost 0+20=20)
Then   X is recommended over Y (lower weighted cost), proving false hits are
       penalized more heavily than false misses by default.

Given  false-hit-penalty=1 (symmetric) on that same data
Then   Y (0 false_hits, 20 misses, cost 20) still loses to X (cost 6); and the
       recommendation shifts as penalty changes, asserted across penalties.

Given  objective=min_false_hits
When   two thresholds tie on zero false_hits
Then   the LOWER threshold is recommended (max reuse among the safe options).

Given  objective=max_precision
Then   the recommended threshold maximizes TP/(TP+false_hits), ties broken by
       higher recall.

Given  a distinct prompt appearing in multiple pairs
When   calibrate runs
Then   the embedding provider is called once per distinct prompt string, not
       once per pair (memoization asserted via a counting fake).

Given  --json
Then   output is valid JSON matching §4.4 shape, no table text, and includes
       false_hit_pairs keyed by threshold.
```

### 5.2 Dataset validation (fail fast, clear errors)

```
Given  a dataset line missing "reuse"
Then   the command exits non-zero naming the line number and the missing field.

Given  a dataset line with empty "a" or "b"
Then   the command exits non-zero naming the line number; no partial run.

Given  a dataset file that does not exist
Then   a clear "dataset not found: <path>" error, exit non-zero.

Given  an empty dataset (zero valid pairs)
Then   a clear error ("no pairs to calibrate"), exit non-zero, no division by zero.

Given  min > max, or step <= 0
Then   an argument validation error before any embedding call.

Given  a malformed line and --strict=true (default)
Then   the command aborts naming the line number; no partial run.

Given  a malformed line and --strict=false
Then   the line is skipped with a warning to stderr, valid pairs still run, and
       the report notes how many lines were skipped.
```

Write §5.1/§5.2 tests first (Pest), red, then implement. §5.1 uses a seeded
`null` provider returning fixed vectors so similarities are exact and no network
is touched.

---

## 6. Edge cases

- **Provider failure mid-sweep** (timeout/5xx on embed) → abort with a clear
  error naming the prompt that failed; do NOT emit a partial/misleading report.
  Calibration is a deliberate offline act, not a request path — fail-closed here,
  unlike the runtime cache's fail-open.
- **Dimension mismatch** (provider returns vectors of unexpected length) → same
  `DimensionMismatchException` as the main package, surfaced clearly.
- **All pairs same label** (all reuse=true, or all false) → run still valid;
  report notes that precision or recall is undefined for one class and skips the
  undefined metric rather than printing NaN.
- **Duplicate identical pairs** → counted as-authored (weight = frequency);
  documented, not deduped (the developer may intentionally weight a case).
- **similarity exactly == threshold** → treated as a HIT (>=), matching runtime
  `search()` semantics. Consistency with production behavior is mandatory; the
  boundary rule here MUST equal the store's.
- **Non-JSONL / malformed line** → report line number, skip vs abort is governed
  by a `--strict` default of abort; documented.
- **Huge dataset** → embeddings memoized and streamed line-by-line; the sweep is
  O(pairs × thresholds) arithmetic on precomputed similarities, no re-embedding.

---

## 7. Non-functional

- **Comparison semantics must match production — but this is a contract, not a
  shared line of code.** The three stores compute cosine differently: `ArrayStore`
  in PHP (`Support/Vectors`), `pgvector` as `1 - (embedding <=> ?)` in SQL, Redis
  via RediSearch `__dist`. They are mathematically equivalent and proven at parity
  by the §5.1 dataset running live across all three. Calibration reuses the PHP
  `Support/Vectors` cosine and the same `>=` boundary rule; the invariant it must
  uphold is **equivalent comparison semantics and an identical inclusive boundary**,
  not "the same code path" (there is no single path — that phrasing was wrong).
  A calibration measuring a different comparison than production would be worse
  than none, so the boundary rule (`>=` counts as a hit) MUST equal the stores'.
- **What this measures — and what it does not.** Calibration scores the *pairwise*
  similarity of A–B. Production does *1-NN over the whole scope*: a false hit in
  production means the single nearest of N stored entries crossed the threshold —
  a different statistic than pairwise. Pairwise precision/recall is therefore an
  **optimistic upper bound** on production quality: a filled cache adds competing
  neighbours, any of which can win falsely, so the real false-hit rate will be no
  lower than calibration suggests. The tool does not simulate 1-NN (it has no full
  corpus at calibration time); it is a principled proxy, and the report says so
  rather than passing pairwise numbers off as direct production measurement.
- **Limits of the method — honest by construction.** Calibration is only as
  truthful as the hand-authored dataset is representative. The most dangerous
  false hits are precisely the pairs the developer never thought to include; the
  tool cannot score semantics it was never shown. This does NOT solve "similar
  vector ≠ equivalent question" — it *quantifies* it on a sample. A clean report
  is necessary but not sufficient confidence, and the output states this plainly:
  it is a reason the v2 verification layer exists (runtime equivalence check
  catches what the dataset missed), not a replacement for it.
- **No side effects.** Never writes to the cache tables, never dispatches
  Hit/Miss events, never calls an LLM. Read-only except stdout.
- **Deterministic under a fixed provider.** Same dataset + same provider vectors
  → identical report. Enables snapshot testing and CI regression on threshold.
- **Honest recommendation.** The recommended threshold is presented as a starting
  point with its false-hit count shown, never as a guarantee. Under the default
  `cost_weighted` objective the recommendation already reflects that false hits
  cost more; the output states plainly that zero-false-hit may be unreachable
  without a verification layer — tying calibration to the v2 trust-layer narrative
  rather than overselling.
- **CI-friendly.** `--json` + non-zero exit on validation failure make it usable
  as a regression gate: fail the build if false_hits at the chosen threshold
  exceed a budget (developer wires the check around the JSON).

---

## 8. Open questions / future

- Feed calibration output into the runtime as a starting point for adaptive
  threshold tuning (§11 of the main spec).
- Optional per-pair weight column so individual business-critical pairs (medical,
  billing) count more heavily than others — a finer grain than the global
  `--false-hit-penalty`, which already ships as the default objective's knob.
- A `--report=html` artifact for the future dashboard package to consume.
