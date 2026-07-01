<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Embedding provider
    |--------------------------------------------------------------------------
    |
    | Which embedding driver turns a prompt into a vector. Its dimension MUST
    | match the migration's vector(N) column — mismatches are caught at boot.
    | Supported: "openai", "voyage", "http", "null".
    |
    */

    'provider' => env('LLM_CACHE_PROVIDER', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Vector store
    |--------------------------------------------------------------------------
    |
    | Where embeddings + responses are stored and searched. "pgvector" is the
    | flagship (Postgres + pgvector extension); "array" is in-memory for tests.
    |
    */

    'store' => env('LLM_CACHE_STORE', 'pgvector'),

    /*
    |--------------------------------------------------------------------------
    | Vector dimension
    |--------------------------------------------------------------------------
    |
    | Fixed per install. Must equal the configured provider's dimensions() and
    | the migration's vector(N). Validated at boot, not at query time.
    |
    */

    'dimension' => (int) env('LLM_CACHE_DIMENSION', 1536),

    /*
    |--------------------------------------------------------------------------
    | Default similarity threshold (cosine)
    |--------------------------------------------------------------------------
    |
    | Minimum cosine similarity for a cache hit. Conservative by default;
    | lowering it trades correctness for hit rate. Overridable per call.
    |
    */

    'threshold' => (float) env('LLM_CACHE_THRESHOLD', 0.95),

    /*
    |--------------------------------------------------------------------------
    | Default TTL (duration)
    |--------------------------------------------------------------------------
    |
    | How long a cached entry stays fresh. A DURATION, not a moment — parsed
    | into a CarbonInterval and resolved to expires_at = now()->add(ttl) at
    | write time. Null means no expiry.
    |
    */

    'ttl' => env('LLM_CACHE_TTL', '1 day'),

    /*
    |--------------------------------------------------------------------------
    | Default scope
    |--------------------------------------------------------------------------
    |
    | Isolation key. "global" is shared; pass "user:{id}" or "tenant:{id}" per
    | call for anything user-specific to prevent cross-scope leakage.
    |
    */

    'scope' => 'global',

    /*
    |--------------------------------------------------------------------------
    | Fail mode
    |--------------------------------------------------------------------------
    |
    | "open"  — on cache-layer failure (embed/search/put), log and run the
    |           callback so the app's LLM path never breaks (default).
    | "closed" — rethrow cache-layer failures.
    |
    */

    'fail_mode' => env('LLM_CACHE_FAIL_MODE', 'open'),

    /*
    |--------------------------------------------------------------------------
    | Provider driver options
    |--------------------------------------------------------------------------
    |
    | Every HTTP-backed provider is bounded by connect/response timeouts and a
    | small retry budget so a slow or hung embedding endpoint can never stall the
    | request thread — fail-open only protects against *thrown* failures, and an
    | unbounded hang throws nothing. Seconds; shared defaults, overridable per
    | provider.
    |
    */

    'providers' => [

        'openai' => [
            'key' => env('OPENAI_API_KEY'),
            'model' => env('LLM_CACHE_OPENAI_MODEL', 'text-embedding-3-small'),
            'connect_timeout' => (int) env('LLM_CACHE_HTTP_CONNECT_TIMEOUT', 3),
            'timeout' => (int) env('LLM_CACHE_HTTP_TIMEOUT', 10),
            'retries' => (int) env('LLM_CACHE_HTTP_RETRIES', 1),
        ],

        'voyage' => [
            'key' => env('VOYAGE_API_KEY'),
            'model' => env('LLM_CACHE_VOYAGE_MODEL', 'voyage-3'),
            'connect_timeout' => (int) env('LLM_CACHE_HTTP_CONNECT_TIMEOUT', 3),
            'timeout' => (int) env('LLM_CACHE_HTTP_TIMEOUT', 10),
            'retries' => (int) env('LLM_CACHE_HTTP_RETRIES', 1),
        ],

        'http' => [
            'endpoint' => env('LLM_CACHE_HTTP_ENDPOINT'),
            'dimensions' => (int) env('LLM_CACHE_HTTP_DIMENSIONS', 1536),
            'connect_timeout' => (int) env('LLM_CACHE_HTTP_CONNECT_TIMEOUT', 3),
            'timeout' => (int) env('LLM_CACHE_HTTP_TIMEOUT', 10),
            'retries' => (int) env('LLM_CACHE_HTTP_RETRIES', 1),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Store driver options
    |--------------------------------------------------------------------------
    */

    'stores' => [

        'pgvector' => [
            'connection' => env('LLM_CACHE_DB_CONNECTION', null),
            'table' => 'llm_cache_entries',
            'index' => env('LLM_CACHE_ANN_INDEX', 'ivfflat'), // ivfflat | hnsw
        ],

        'redis' => [
            'connection' => env('LLM_CACHE_REDIS_CONNECTION', 'default'),
            'index' => env('LLM_CACHE_REDIS_INDEX', 'llm_cache_idx'),
            'prefix' => env('LLM_CACHE_REDIS_PREFIX', 'llm_cache:'),
            'algorithm' => env('LLM_CACHE_REDIS_ALGO', 'FLAT'), // FLAT | HNSW
        ],

        'array' => [
            // in-memory; no options
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Stats & event recording (§8)
    |--------------------------------------------------------------------------
    |
    | When `record` is true, an optional listener persists every Hit/Miss event
    | to the `table` (driver-agnostic — works on any DB). The `llm-cache:stats`
    | command aggregates that history. `estimated_tokens_saved` is reported as
    | hits × avg_tokens_per_generation (an estimate; precise accounting belongs
    | to a downstream listener such as laravel-ai-budget).
    |
    */

    'stats' => [
        'record' => (bool) env('LLM_CACHE_STATS_RECORD', false),
        'connection' => env('LLM_CACHE_STATS_CONNECTION', null),
        'table' => 'llm_cache_events',
        'avg_tokens_per_generation' => (int) env('LLM_CACHE_STATS_AVG_TOKENS', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Concurrent-miss deduplication (§9)
    |--------------------------------------------------------------------------
    |
    | When enabled, a miss acquires an atomic lock keyed by sha1(scope|prompt)
    | so concurrent identical misses generate once: the leader generates and
    | stores; waiters block, then re-read the cache and reuse the result. The
    | lock `store` must support atomic locks (redis, memcached, database,
    | dynamodb, array). Fails open if the lock store is unavailable.
    |
    | `ttl` MUST exceed worst-case generation time. Both values are in seconds.
    |
    */

    'lock' => [
        'enabled' => (bool) env('LLM_CACHE_LOCK', false),
        'store' => env('LLM_CACHE_LOCK_STORE', null),
        'ttl' => (int) env('LLM_CACHE_LOCK_TTL', 10),
        'wait' => (int) env('LLM_CACHE_LOCK_WAIT', 10),
    ],

];
