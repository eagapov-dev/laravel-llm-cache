# laravel-llm-cache

Semantic caching for LLM calls in Laravel. Matches prompts by **meaning**
(embedding similarity) instead of exact string, so paraphrased-but-equivalent
requests reuse a cached response instead of paying for a fresh generation.

> "what are your opening hours?" and "when do you open?" hit the **same** cache
> entry and skip the second model call. Genuinely different prompts still miss
> and generate.

- Wrap **any** LLM call in `SemanticCache::remember()` — the package never calls
  an LLM itself, so it's SDK-agnostic.
- Driver-based **embedding providers** (`openai`, `voyage`, `http`, `null`) and
  **vector stores** (`pgvector` flagship, `array` for tests).
- **Fail-open by default** — a cache-layer outage never breaks your app's LLM path.
- Per-scope isolation, TTL expiry, hit/miss events, and an `llm-cache:stats`
  command with a worked cost model (see [Does it pay off?](#does-it-pay-off-the-benchmark)).

---

## How it works

```
remember($prompt, $callback):
  1. embed($prompt)                    → vector           (configured provider)
  2. store.search(vector, scope, τ)    → nearest entry ≥ τ cosine similarity
  3. hit  → return cached response, dispatch CacheHitEvent   (no LLM call)
     miss → run $callback (the real LLM call), store it,
            dispatch CacheMissEvent, return the fresh response
```

Similarity is cosine. On the `pgvector` driver the threshold is applied **in SQL**
via an ANN index (`ivfflat`/`hnsw`); on `array` it's computed in PHP. Same
observable behaviour either way.

---

## Requirements

- PHP 8.2+
- Laravel 11
- For the flagship store: PostgreSQL with the [`pgvector`](https://github.com/pgvector/pgvector) extension

---

## Installation

```bash
composer require yegoragapov/laravel-llm-cache
```

Publish the config and migration:

```bash
php artisan vendor:publish --tag=llm-cache-config
php artisan vendor:publish --tag=llm-cache-migrations
php artisan migrate
```

Set your provider and store in `.env`:

```dotenv
LLM_CACHE_PROVIDER=voyage       # openai | voyage | http | null
LLM_CACHE_STORE=pgvector        # pgvector | array
LLM_CACHE_DIMENSION=1024        # MUST match the provider's output dimension
VOYAGE_API_KEY=...
```

> **Dimension is a hard constraint.** `llm-cache.dimension` must equal both the
> embedding provider's output size and the migration's `vector(N)`. A mismatch
> throws a `DimensionMismatchException` **at boot**, not at query time. Changing
> provider/dimension later requires a new migration + a cache flush.

The pgvector migration enables the extension and creates the ANN cosine index
for you.

---

## Quick start

```php
use Yegoragapov\LlmCache\Facades\SemanticCache;
use Anthropic\Client;

$answer = SemanticCache::remember(
    $userQuestion,
    function () use ($client, $userQuestion): string {
        // The real (expensive) LLM call — only runs on a cache miss.
        $message = $client->messages->create(
            model: 'claude-opus-4-8',
            maxTokens: 1024,
            messages: [['role' => 'user', 'content' => $userQuestion]],
        );

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $block->text;
            }
        }

        return '';
    },
);
```

Everything after `$prompt` and the callback is optional:

```php
SemanticCache::remember(
    prompt: $userQuestion,
    callback: fn (): string => $llm->answer($userQuestion),
    threshold: 0.97,                              // per-call override of config
    ttl: \Carbon\CarbonInterval::hours(6),        // a DURATION, not a moment
    scope: "user:{$user->id}",                    // isolation key (see below)
    meta: ['model' => 'claude-opus-4-8'],         // audit metadata; `model` is a typed column
);

SemanticCache::forget("user:{$user->id}");        // remove one scope, returns rows removed
SemanticCache::flush();                            // clear everything
```

---

## Configuration

`config/llm-cache.php` (all overridable via env):

| Key | Default | Purpose |
|-----|---------|---------|
| `provider` | `null` | Embedding driver: `openai`, `voyage`, `http`, `null` |
| `store` | `pgvector` | Vector store: `pgvector`, `array` |
| `dimension` | `1536` | Vector size — must match provider **and** migration |
| `threshold` | `0.95` | Minimum cosine similarity for a hit (conservative) |
| `ttl` | `'1 day'` | Default freshness **duration** (`null` = no expiry) |
| `scope` | `global` | Default isolation key |
| `fail_mode` | `open` | `open` = log & run callback on cache failure; `closed` = rethrow |
| `stats.record` | `false` | Persist Hit/Miss events for `llm-cache:stats` |
| `stats.avg_tokens_per_generation` | `500` | Estimate basis for "tokens saved" |
| `lock.enabled` | `false` | Dedupe concurrent identical misses (see below) |
| `lock.ttl` / `lock.wait` | `10` / `10` | Leader hold / waiter block, in seconds |

---

## Drivers

### Embedding providers

| Driver | Notes | `dimension` |
|--------|-------|-------------|
| `openai` | `text-embedding-3-small` (1536) / `-large` (3072) | 1536 / 3072 |
| `voyage` | Voyage AI — recommended for Anthropic-centric stacks (Anthropic ships no first-party embeddings) | 1024 (`voyage-3`) |
| `http` | Any self-hosted endpoint (e.g. sentence-transformers) returning `{ "embedding": [...] }` | your value |
| `null` | Deterministic hashed pseudo-vector — for tests, no network | configurable |

Write your own by implementing `Yegoragapov\LlmCache\Contracts\EmbeddingProvider`.

### Vector stores

| Driver | Similarity | Persistence | Use |
|--------|-----------|-------------|-----|
| `pgvector` | cosine, **in SQL** via ANN index | Postgres table | production |
| `redis` | cosine, **in RediSearch** KNN | Redis Stack / Redis 8+ (search module) | production |
| `array` | cosine, in PHP | in-memory (per request) | tests |

The `redis` driver needs Redis with the RediSearch module (Redis Stack, or
Redis 8+ with search). It stores each entry as a hash, creates an
`FT.CREATE ... VECTOR ... COSINE` index lazily, and searches via `FT.SEARCH`
KNN — the scope filter and threshold run in the query, not in PHP. Works with
the phpredis extension or the predis client; set `LLM_CACHE_STORE=redis` and
`stores.redis.connection`.

Write your own by implementing `Yegoragapov\LlmCache\Contracts\VectorStore`.

---

## Scopes & isolation

`scope` prevents cross-user/tenant leakage. `global` is shared across everyone;
pass a per-entity scope for anything user-specific:

```php
SemanticCache::remember($q, $fn, scope: "user:{$user->id}");
SemanticCache::remember($q, $fn, scope: "tenant:{$tenant->id}");
```

A prompt cached under `user:1` will **not** be served to `user:2`. Personalized
or PII responses under the `global` scope are a documented footgun — always scope
them.

### Multi-turn / conversation context

By default `remember()` is stateless — it caches by the prompt alone. A
context-dependent follow-up ("and what about refunds?") means different things in
different conversations, so pass the prior turns as `context` to isolate the
entry by a digest of that context:

```php
$reply = SemanticCache::remember(
    prompt: $followUp,
    callback: fn () => $llm->answer($conversation),
    scope: "user:{$user->id}",
    context: $priorTurns,        // string, or an array of prior messages
);
```

The **prompt** is still matched semantically; the **context** is matched exactly
(same digest → same scope). The same follow-up under an identical context hits;
under any different context it misses — no cross-conversation leakage. Passing no
`context` keeps the stateless behaviour.

To forget one conversation's entries, derive its scope:

```php
SemanticCache::forget(SemanticCache::contextScope("user:{$user->id}", $priorTurns));
```

---

## Events

Every `remember()` emits exactly one event:

- `CacheHitEvent(prompt, similarity, scope, entryId)`
- `CacheMissEvent(prompt, scope)`

Listen to compute savings, wire budgeting, or feed dashboards:

```php
Event::listen(CacheHitEvent::class, function (CacheHitEvent $e) {
    Log::info("semantic hit @ {$e->similarity} for scope {$e->scope}");
});
```

---

## Stats

Turn on recording and the package persists each event, then summarises them:

```dotenv
LLM_CACHE_STATS_RECORD=true
```

```bash
php artisan llm-cache:stats
php artisan llm-cache:stats --days=7 --json
```

```
 Metric              Value
 Total calls         12,904
 Hits                8,133
 Misses              4,771
 Hit rate            63.0%
 Est. tokens saved   4,066,500 (≈500/gen)
```

`estimated_tokens_saved = hits × stats.avg_tokens_per_generation` — an **estimate**
(the package stores no real token counts; attach a downstream listener like
`laravel-ai-budget` for precise accounting).

---

## Pruning expired entries

Expired entries are excluded from lookups immediately, but they are only
physically removed by `llm-cache:prune`. Without it the store (and its ANN
index) grows without bound — Redis also keeps a native key TTL, but pgvector
relies entirely on this command. Schedule it:

```bash
php artisan llm-cache:prune
```

```php
// bootstrap/app.php (Laravel 11) or app/Console/Kernel.php
$schedule->command('llm-cache:prune')->daily();
```

---

## Concurrent-miss deduplication (optional)

Under a thundering herd — the same prompt hammered by many requests at once —
every request misses before the first one finishes writing, so they all generate.
Enable the lock to collapse that to a single generation:

```dotenv
LLM_CACHE_LOCK=true
LLM_CACHE_LOCK_STORE=redis     # any cache store with atomic locks; null = default
LLM_CACHE_LOCK_TTL=10          # seconds; MUST exceed worst-case generation time
LLM_CACHE_LOCK_WAIT=10         # seconds a waiter blocks before failing open
```

On a miss, `remember()` takes an atomic lock keyed by `sha1(scope|prompt)`. The
**leader** generates, stores, and releases; **waiters** block, then re-read the
cache — which now hits — and reuse the leader's response without generating.

- Dedupes **byte-identical** concurrent prompts (same `scope|prompt`).
  Semantically-near-but-not-identical concurrent misses are still tolerated.
- **Fails open**: if the lock store is missing, lacks atomic-lock support, or a
  waiter exceeds `lock.wait`, the request generates without dedup — never errors.
- `lock.ttl` must exceed your slowest generation, or a waiter could acquire the
  lock mid-generation and generate a second time.

Off by default — turn it on when duplicate-burst traffic makes double-generation
a real cost.

---

## Does it pay off? The benchmark

A semantic cache adds an embedding call to **every** request to potentially skip a
generation on **some**. That trade is worth proving, not asserting.

### The cost model

Let, per call:

- `Ce` = embedding cost (paid on **every** call)
- `Cg` = generation cost (the call a hit avoids)
- `h`  = hit rate (fraction of calls served from cache)

```
cost without cache  =  Cg
cost with cache     =  Ce  +  (1 − h)·Cg
savings per call    =  h·Cg − Ce
```

Savings turn positive once `h·Cg > Ce`, i.e. at the **break-even hit rate**:

```
h* = Ce / Cg
```

That's the whole story: you win as soon as the share of semantically-duplicate
prompts exceeds the ratio of embedding cost to generation cost. Because
embeddings are 1–2 orders of magnitude cheaper than generation, `h*` is tiny.

### Worked numbers

A typical support/RAG answer: **~1,500 input + ~300 output tokens** per
generation. Embedding the user prompt (~1,500 tokens, worst case — you often
embed just the short question).

Generation cost per call, Claude pricing (per MTok, as of 2026-07):

| Model | Input | Output | `Cg` (1.5k in + 0.3k out) |
|-------|------:|-------:|--------------------------:|
| Claude Haiku 4.5  | $1  | $5  | **$0.0030** |
| Claude Sonnet 4.6 | $3  | $15 | **$0.0090** |
| Claude Opus 4.8   | $5  | $25 | **$0.0150** |

Embedding cost per call (representative third-party pricing — **verify at your
provider**, prices drift):

| Embedder | Price / MTok | `Ce` (1.5k tokens) |
|----------|-------------:|-------------------:|
| OpenAI `text-embedding-3-small` | ~$0.02 | **$0.00003** |
| Voyage `voyage-3`               | ~$0.06 | **$0.00009** |
| OpenAI `text-embedding-3-large` | ~$0.13 | **$0.000195** |

Break-even hit rate `h* = Ce / Cg`:

| | Haiku `Cg=$0.0030` | Sonnet `Cg=$0.0090` | Opus `Cg=$0.0150` |
|--|--:|--:|--:|
| OpenAI small `Ce=$0.00003`  | **1.0 %** | 0.33 % | 0.20 % |
| Voyage `Ce=$0.00009`        | 3.0 %     | 1.0 %  | 0.60 % |
| OpenAI large `Ce=$0.000195` | 6.5 %     | 2.2 %  | 1.3 %  |

**You need roughly 1–6 % of prompts to be semantic duplicates just to break even**
— and that's the worst case where you embed the *full* prompt. Embed only the
short user question (~50 tokens) and `h*` drops another ~30×, into the fraction of
a percent. Any FAQ, support bot, or docs assistant clears this easily.

### Net savings at a realistic hit rate

1,000,000 calls/month, Haiku generation (`Cg=$0.0030`), OpenAI-small embedding of
the full prompt (`Ce=$0.00003`), **30 % hit rate**:

```
without cache : 1,000,000 × $0.0030                       = $3,000 / mo
with cache    : 1,000,000 × $0.00003   (embeddings)       =    $30
              +   700,000 × $0.0030    (misses generate)  = $2,100
              ────────────────────────────────────────────────────
                                                    total = $2,130 / mo
savings       : $870 / mo  (≈29 %)
```

At 60 % hit rate the same workload saves **~$1,770/mo (≈59 %)**. Latency wins too:
a hit returns after only an embedding + an ANN lookup (sub-10 ms at 10⁵ entries),
skipping generation entirely.

### The honest caveats

- **Every call pays `Ce`.** On a miss you pay `Ce + Cg` — marginally more cost and
  latency than no cache. The model above already accounts for this; it's why `h*`
  exists.
- **Lowering `threshold` raises hit rate but risks serving a wrong answer.** The
  default `0.95` is deliberately conservative. Tune against your own prompts.
- **Best for stable, reference-style answers** (hours, policies, docs, FAQs), not
  creative or per-request-unique generation. Use `ttl` to bound staleness.
- Numbers above are illustrative with dated, third-party embedding prices —
  plug in your real `Ce`, `Cg`, and measured `h` (from `llm-cache:stats`) before
  quoting them.

---

## Edge-case behaviour

| Situation | Behaviour |
|-----------|-----------|
| Empty prompt | Cache skipped entirely — callback runs, nothing stored, no event |
| Embedding provider fails | `fail_mode=open`: log, run callback, return fresh (uncached). `closed`: rethrow |
| Store `search` fails | Fail-open (run callback) |
| Store `put` fails | Logged and swallowed — the response is still returned |
| Expired entry (`expires_at` past) | Treated as a miss and refreshed |
| Concurrent identical misses | Both may generate; last write wins. Enable `lock` to dedupe (see above) |
| Provider silently changes model | Vector-length assertion throws before storing a corrupt row |

---

## Testing

The suite runs on the in-memory `array` store by default (no DB needed). To also
exercise the `pgvector` driver against a real Postgres:

```bash
docker run -d --name llmcache-pg \
  -e POSTGRES_USER=llmcache -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=llmcache_test \
  -p 55432:5432 pgvector/pgvector:pg16

LLM_CACHE_TEST_PGVECTOR=1 ./vendor/bin/pest
```

And the `redis` driver against a real Redis Stack:

```bash
docker run -d --name llmcache-redis -p 56379:6379 redis/redis-stack-server:latest

LLM_CACHE_TEST_REDIS=1 ./vendor/bin/pest
```

Without the flags, the pgvector and redis cases are skipped and the array-driver
behavioural suite (identical assertions) runs. Quality gates:

```bash
./vendor/bin/pest
./vendor/bin/phpstan analyse --level=8
```

---

## License

MIT.
