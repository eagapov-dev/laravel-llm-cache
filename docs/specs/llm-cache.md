# Spec: laravel-llm-cache

Semantic caching layer for LLM calls in Laravel. Matches prompts by meaning
(embedding similarity) instead of exact string, so paraphrased-but-equivalent
requests reuse a cached response instead of paying for a new generation.

---

## 1. Goal

Reduce LLM token spend and latency by serving cached responses to prompts that
are semantically equivalent to prior ones. A user asking "what are your opening
hours?" and "when do you open?" should hit the same cache entry and avoid a
second model call, while genuinely different prompts still miss and generate.

The package is embedding-provider- and vector-store-agnostic via driver
architecture, with pgvector as the flagship store (fits a standard Laravel +
Postgres app with no extra infra).

---

## 2. Scope

**In scope**
- `SemanticCache` facade with a `remember()` API wrapping any LLM call.
- Driver-based embedding providers (interface + built-in drivers).
- Driver-based vector stores (interface + built-in drivers: pgvector, array).
- Configurable similarity threshold (global + per-call override).
- TTL-based expiry and manual/tag invalidation.
- Per-scope key isolation (global vs per-user) to prevent cross-user leakage.
- Hit/miss + token-saved metrics via events.
- Publishable config and migration.

**Out of scope**
- Conversation-context-aware caching (multi-turn). Only stateless single-prompt
  caching is supported in v1. Context-dependent prompts must opt out.
- Automatic embedding-cost accounting/budgeting. Left to laravel-ai-budget;
  this package only emits events another package can consume.
- Streaming responses. `remember()` caches complete responses only.
- Shipping/hosting an embedding model. Providers are external APIs or a
  user-provided endpoint.
- Automatic invalidation on upstream data change (user wires that themselves).

---

## 3. Data model

### Table: `llm_cache_entries` (pgvector driver)

```
id                bigint PK
scope             string          -- 'global' or "user:{id}" etc.
embedding         vector(N)       -- N = provider dimensions, from config
response          text            -- cached LLM response payload
prompt_preview    string nullable -- first ~255 chars, debugging only
provider          string          -- embedding provider name (audit)
model             string nullable -- LLM model that produced the response
meta              jsonb nullable  -- arbitrary caller metadata
hits              integer default 0
expires_at        timestamp nullable
created_at        timestamp
updated_at        timestamp

INDEX  (scope)
INDEX  (expires_at)
-- pgvector ANN index, cosine:
INDEX  USING ivfflat (embedding vector_cosine_ops)  -- or hnsw
```

Notes:
- Vector dimension `N` is fixed per install (must match the embedding provider).
  Changing provider/dimension requires a migration + cache flush; documented as
  a hard constraint, validated at boot.
- `scope` enforces isolation. Default `global`; caller passes a scope for
  per-user or per-tenant answers.

---

## 4. Contracts

### 4.1 Embedding provider

```php
interface EmbeddingProvider
{
    /** @return array<float> */
    public function embed(string $text): array;

    public function dimensions(): int;

    public function name(): string;
}
```

Built-in drivers:
- `openai`   — text-embedding-3-small / -large
- `voyage`   — Voyage AI (recommended for Anthropic-centric stacks; Anthropic
               ships no first-party embeddings)
- `http`     — generic user-configured endpoint (self-hosted sentence-transformers)
- `null`     — deterministic fake for tests (hashed pseudo-vector)

### 4.2 Vector store

```php
interface VectorStore
{
    public function search(
        array $vector,
        string $scope,
        float $threshold
    ): ?CacheHit;

    public function put(CacheEntry $entry): void;

    public function forget(string $scope): int;   // returns rows removed

    public function flush(): int;
}
```

Built-in drivers: `pgvector` (flagship), `array` (in-memory, tests).

`CacheHit` DTO: `response`, `similarity`, `meta`, `entryId`.

`CacheEntry` DTO (write payload for `put()`):
`vector`, `response`, `scope`, `expiresAt (?CarbonInterface — absolute)`,
`model (?string)`, `promptPreview (?string)`, `meta (array)`.

`provider` is NOT part of `CacheEntry`: the store knows its embedding provider
from its own configuration and stamps the column itself. `model` and
`promptPreview` are per-call and therefore travel on the DTO as typed fields
(not folded into `meta`), so §3's typed columns stay queryable — e.g. stats
grouped by `model`.

### 4.3 Facade / public API

```php
SemanticCache::remember(
    string          $prompt,
    Closure         $callback,          // fn(): string — the real LLM call
    ?float          $threshold = null,  // defaults to config
    ?CarbonInterval $ttl = null,        // DURATION, not a moment; defaults to config
    string          $scope = 'global',
    array           $meta = [],
): string;

SemanticCache::forget(string $scope): int;
SemanticCache::flush(): int;
```

`$ttl` is a **duration** (`CarbonInterval`, e.g. `CarbonInterval::day()`), not
an absolute moment. The public API speaks "how long to cache"; the store speaks
"until when". Conversion happens once, at write time:
`expires_at = now()->add($ttl)`. The config default is likewise a duration
(`'ttl' => '1 day'`). `CacheEntry::$expiresAt` (the store-side value) remains
the resolved absolute timestamp.

Behavior of `remember()`:
1. `embed(prompt)` via configured provider.
2. `store->search(vector, scope, threshold)`.
3. Hit → increment `hits`, dispatch `CacheHitEvent`, return cached response.
4. Miss → run `callback`, build `CacheEntry` (resolve `expires_at` from `$ttl`),
   `store->put($entry)`, dispatch `CacheMissEvent`, return fresh response.

### 4.4 Events

- `CacheHitEvent(prompt, similarity, scope, entryId)`
- `CacheMissEvent(prompt, scope)`
- Both carry enough for a listener (e.g. laravel-ai-budget) to compute savings.

---

## 5. Acceptance criteria

The **behavioral** criteria below (§5.1) define the `VectorStore` contract and
MUST pass identically on BOTH the `array` and `pgvector` drivers — they assert
*behavior*, not implementation. The `array` driver computes cosine similarity in
PHP; `pgvector` computes it in SQL. Same observable outcome either way. Run the
suite parameterized over both drivers.

The **implementation** criteria (§5.2) are `pgvector`-specific and assert *how*
the flagship driver achieves the behavior (ANN index, SQL-side filtering).

### 5.1 Behavioral (both drivers)

```
Given  an empty cache
When   remember() is called with prompt P
Then   the callback runs exactly once, response is stored,
       CacheMissEvent is dispatched, and the callback's value is returned.

Given  a cached entry for "what are your opening hours?"
When   remember() is called with "when do you open?" and their cosine
       similarity >= threshold
Then   the callback does NOT run, the cached response is returned,
       the entry's hits is incremented, CacheHitEvent is dispatched.

Given  a cached entry for prompt P
When   remember() is called with an unrelated prompt whose similarity < threshold
Then   it is treated as a miss: callback runs, new entry stored.

Given  a per-call threshold override
When   remember() is called with threshold = 0.99
Then   the override is used for search, not the config default.

Given  a cached entry stored under scope "user:1"
When   remember() is called with the same prompt under scope "user:2"
Then   it is a miss (no cross-scope leakage); a separate entry is stored.

Given  a cached entry whose expires_at is in the past
When   remember() is called with a matching prompt
Then   the expired entry is not returned; it is a miss and is refreshed.

Given  forget("user:1") is called
Then   only entries with scope "user:1" are removed; count returned.
```

### 5.2 Implementation (pgvector only)

```
Given  the pgvector driver and a populated cache
When   search() runs
Then   the threshold is applied as a cosine-distance filter in SQL via the ANN
       index (ivfflat/hnsw); similarity is NOT computed in PHP.

Given  the configured provider dimension != the migration's vector dimension
When   the service boots
Then   a clear configuration exception is thrown at boot, not at query time.
```

Write tests from this section first (Pest), confirm they fail, then implement.
Behavioral tests (§5.1) use a Pest dataset over `['array', 'pgvector']`.

---

## 6. Edge cases

- **Empty prompt** → skip cache entirely, run callback, do not store. Return as-is.
- **Embedding provider failure (timeout/5xx)** → fail open: log, run callback,
  return fresh response without caching. A cache-layer outage must never break
  the app's core LLM path. Configurable to fail-closed if desired.
- **Vector store failure on search** → fail open (run callback). Failure on
  `put` → log and swallow; response still returned.
- **Concurrent identical misses** → both may generate (accepted). Last write
  wins on `put`; duplicate near-vectors tolerated. No distributed lock in v1
  (documented; optional lock is a future enhancement).
- **Dimension mismatch mid-flight** (provider silently changes model) → detected
  by vector length assertion on `embed`, throws before storing a corrupt row.
- **Threshold too low** → package ships a conservative default (0.95 cosine) and
  README warns that lowering it trades correctness for hit rate. Not enforced.
- **Non-deterministic responses cached** → caller's responsibility; README
  states semantic cache is for stable, reference-style answers, not creative
  generation. `ttl` short-circuits staleness.
- **Personalized/PII responses under `global` scope** → documented footgun;
  scope must be set per-user for anything user-specific. No auto-detection.

---

## 7. Non-functional

- **Fail-open by default.** Cache is an optimization, never a hard dependency in
  the request path (see edge cases). One config flag flips to fail-closed.
- **Observability.** Every call emits exactly one Hit or Miss event. Package
  exposes an optional artisan command `llm-cache:stats` summarizing hit rate and
  estimated tokens saved (from event history if a listener persists it).
- **Cost honesty.** README includes a worked benchmark: embedding cost per call
  vs generation cost saved, with the break-even hit rate. Embeddings are ~1–2
  orders of magnitude cheaper than generation; the doc proves it rather than
  asserting it.
- **Performance.** pgvector search uses an ANN index (ivfflat or hnsw); target
  sub-10ms lookup at 10^5 entries. Threshold applied as cosine distance filter
  in SQL, not in PHP.
- **Portability.** No hard dependency on a specific LLM SDK. `remember()` wraps
  any closure; the package never calls an LLM itself.
- **Config-first.** Provider, store, dimension, default threshold, default TTL,
  fail mode, and default scope all live in `config/llm-cache.php`.

---

## 8. Stats command & event recording

Concretises the §7 observability line: `llm-cache:stats` reads from a persisted
**event history** written by an optional listener the package ships.

### 8.1 Event recording (opt-in)

- Config `stats.record` (default `false`). When `true`, the package registers a
  `RecordCacheEvent` subscriber for `CacheHitEvent` and `CacheMissEvent`.
- Each dispatched event appends one row to `llm_cache_events` (publishable
  migration): `type` (`hit`|`miss`), `scope`, `similarity` (nullable — hits
  only), `created_at`. Append-only; no `updated_at`.
- Recording uses `stats.connection` (null = default connection) and
  `stats.table` (default `llm_cache_events`). Driver-agnostic — no vector
  column, so it works on any database (sqlite/mysql/pgsql).
- **Recording must never break the request path**: a write failure is logged
  and swallowed, consistent with the package's fail-open stance.

### 8.2 `llm-cache:stats` command

Aggregates the event history and prints: total calls, hits, misses, hit rate,
and **estimated** tokens saved.

- `estimated_tokens_saved = hits × stats.avg_tokens_per_generation` (config,
  default `500`), explicitly labelled an estimate. The package stores no real
  token counts; a downstream listener (laravel-ai-budget) can supply precise
  accounting.
- `--days=N` restricts counting to events within the last N days (default: all
  time).
- `--json` emits machine-readable output instead of a table.
- If recording is disabled or the table is absent, the command prints guidance
  on how to enable recording and exits `0` (never crashes).

### 8.3 Acceptance (extends §5)

```
Given  recording enabled and H 'hit' rows + M 'miss' rows in llm_cache_events
When   `llm-cache:stats --json` runs
Then   it reports total=H+M, hits=H, misses=M, hit_rate=H/(H+M),
       estimated_tokens_saved = H × avg_tokens_per_generation, exit 0.

Given  an empty events table (recording enabled)
When   the command runs
Then   it reports 0 calls and a 0% hit rate, exit 0, no error (no divide-by-zero).

Given  events older and newer than N days
When   the command runs with --days=N
Then   only events within the last N days are counted.

Given  recording disabled or the table absent
When   the command runs
Then   it prints how to enable recording and exits 0.

Given  the RecordCacheEvent subscriber is active
When   a CacheHitEvent(scope, similarity) is dispatched
Then   one 'hit' row is inserted with that scope and similarity;
       a CacheMissEvent inserts a 'miss' row (similarity null).
```

---

## 9. Open questions / future

- Optional distributed lock to dedupe concurrent misses.
- Multi-turn context hashing (fold a context digest into the scope or key).
- Redis vector store driver as a second flagship.
- Adaptive threshold tuning from observed hit/miss quality.
