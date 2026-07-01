<?php

namespace Yegoragapov\LlmCache\Stores;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use RuntimeException;
use Yegoragapov\LlmCache\Contracts\VectorStore;
use Yegoragapov\LlmCache\DataObjects\CacheEntry;
use Yegoragapov\LlmCache\DataObjects\CacheHit;
use Yegoragapov\LlmCache\Exceptions\DimensionMismatchException;

/**
 * Second flagship store: Redis Stack / Redis 8+ with the RediSearch module.
 *
 * Each entry is a hash; search is a RediSearch KNN query with COSINE distance
 * (`FT.SEARCH ... =>[KNN 1 @embedding $vec]`). The scope pre-filter and the
 * cosine threshold are applied in the query — server-side ANN, not in PHP
 * (parity with the pgvector driver). Vectors are stored as FLOAT32 little-endian
 * blobs. Works with either the phpredis extension or the predis client.
 *
 * @param array<string, mixed> $config The `llm-cache.stores.redis` config.
 */
class RedisStore implements VectorStore
{
    /** Sentinel `expires_at` for "never expires" (year 2286). */
    private const NEVER = 9999999999;

    private bool $ensured = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected RedisFactory $redis,
        protected array $config,
        protected int $dimensions,
        protected string $providerName,
    ) {
    }

    public function search(array $vector, string $scope, float $threshold): ?CacheHit
    {
        $this->ensureIndex();

        $now = time();
        $query = '(@scope:{'.$this->escapeTag($scope).'} @expires_at:[('.$now.' +inf])'
            .'=>[KNN 1 @embedding $BLOB AS __dist]';

        /** @var array<int, mixed> $reply */
        $reply = (array) $this->raw([
            'FT.SEARCH', $this->index(), $query,
            'PARAMS', '2', 'BLOB', $this->vectorBlob($vector),
            'RETURN', '3', 'response', 'meta', '__dist',
            'SORTBY', '__dist', 'ASC',
            'DIALECT', '2',
        ]);

        $total = (int) ($reply[0] ?? 0);

        if ($total < 1 || ! isset($reply[1], $reply[2])) {
            return null;
        }

        $key = (string) $reply[1];
        $fields = $this->fieldsToMap((array) $reply[2]);
        $similarity = 1.0 - (float) ($fields['__dist'] ?? 1.0);

        if ($similarity < $threshold) {
            return null;
        }

        // Best-effort hit counter — never let a failed increment demote a valid
        // hit to a miss (which would regenerate + store a duplicate).
        try {
            $this->raw(['HINCRBY', $key, 'hits', '1']);
        } catch (\Throwable $e) {
            // non-essential; swallow
        }

        return new CacheHit(
            response: (string) ($fields['response'] ?? ''),
            similarity: $similarity,
            meta: $this->decodeMeta($fields['meta'] ?? null),
            entryId: (int) str_replace($this->prefix(), '', $key),
        );
    }

    public function put(CacheEntry $entry): void
    {
        $this->ensureIndex();

        $id = (int) $this->raw(['INCR', $this->prefix().':id']);
        $key = $this->prefix().$id;

        $expiresAt = $entry->expiresAt !== null ? $entry->expiresAt->getTimestamp() : self::NEVER;
        $meta = $entry->meta === [] ? '' : (string) json_encode($entry->meta, JSON_THROW_ON_ERROR);

        $this->raw([
            'HSET', $key,
            'embedding', $this->vectorBlob($entry->vector),
            'response', $entry->response,
            'scope', $entry->scope,
            'expires_at', (string) $expiresAt,
            'hits', '0',
            'provider', $this->providerName,
            'model', (string) ($entry->model ?? ''),
            'prompt_preview', (string) ($entry->promptPreview ?? ''),
            'meta', $meta,
        ]);

        // Give the key a native TTL so Redis reclaims memory and RediSearch drops
        // the doc automatically once it expires — the @expires_at query filter
        // then becomes a redundant safety net rather than the only cleanup path.
        if ($entry->expiresAt !== null) {
            $this->raw(['PEXPIREAT', $key, (string) ($entry->expiresAt->getTimestamp() * 1000)]);
        }
    }

    public function forget(string $scope): int
    {
        $this->ensureIndex();

        $removed = 0;

        // RediSearch caps LIMIT at 10000; delete in pages until the scope is dry
        // (deleting removes docs from the index, so each pass returns the next batch).
        do {
            /** @var array<int, mixed> $reply */
            $reply = (array) $this->raw([
                'FT.SEARCH', $this->index(), '@scope:{'.$this->escapeTag($scope).'}',
                'NOCONTENT', 'LIMIT', '0', '10000',
            ]);

            $keys = array_slice($reply, 1);

            if ($keys !== []) {
                $this->raw(array_merge(['DEL'], array_map('strval', $keys)));
                $removed += count($keys);
            }
        } while (count($keys) >= 10000);

        return $removed;
    }

    public function flush(): int
    {
        $this->ensureIndex();

        /** @var array<int, mixed> $reply */
        $reply = (array) $this->raw(['FT.SEARCH', $this->index(), '*', 'NOCONTENT', 'LIMIT', '0', '0']);
        $total = (int) ($reply[0] ?? 0);

        // `DD` drops the indexed hashes too; the id counter (a string) survives.
        $this->raw(['FT.DROPINDEX', $this->index(), 'DD']);
        $this->ensured = false;

        return $total;
    }

    public function purgeExpired(): int
    {
        $this->ensureIndex();

        $now = time();
        $removed = 0;

        // Native key TTLs (set in put()) already reclaim most expired entries;
        // this sweeps any that lack a TTL or predate the feature. Page like
        // forget() since deletion shrinks the result set each pass.
        do {
            /** @var array<int, mixed> $reply */
            $reply = (array) $this->raw([
                'FT.SEARCH', $this->index(), '@expires_at:[-inf ('.$now.']',
                'NOCONTENT', 'LIMIT', '0', '10000',
                'DIALECT', '2',
            ]);

            $keys = array_slice($reply, 1);

            if ($keys !== []) {
                $this->raw(array_merge(['DEL'], array_map('strval', $keys)));
                $removed += count($keys);
            }
        } while (count($keys) >= 10000);

        return $removed;
    }

    /**
     * Serialize a vector to a FLOAT32 little-endian blob for RediSearch.
     *
     * @param array<int, float> $vector
     */
    public function vectorBlob(array $vector): string
    {
        if (count($vector) !== $this->dimensions) {
            throw DimensionMismatchException::make(
                $this->providerName,
                count($vector),
                $this->dimensions,
            );
        }

        return pack('g*', ...array_map('floatval', $vector));
    }

    protected function ensureIndex(): void
    {
        if ($this->ensured) {
            return;
        }

        try {
            $this->raw([
                'FT.CREATE', $this->index(),
                'ON', 'HASH', 'PREFIX', '1', $this->prefix(),
                'SCHEMA',
                // CASESENSITIVE so scopes isolate byte-exactly, matching the
                // pgvector/array drivers. Without it RediSearch lowercases tags,
                // silently collapsing e.g. "User:42" and "user:42".
                'scope', 'TAG', 'CASESENSITIVE',
                'expires_at', 'NUMERIC',
                'embedding', 'VECTOR', $this->algorithm(), '6',
                'TYPE', 'FLOAT32', 'DIM', (string) $this->dimensions, 'DISTANCE_METRIC', 'COSINE',
            ]);
        } catch (\Throwable $e) {
            if (! str_contains(strtolower($e->getMessage()), 'already exists')) {
                throw $e;
            }
        }

        $this->ensured = true;
    }

    /**
     * Run a raw Redis command across either client, throwing on an error reply.
     *
     * @param array<int, mixed> $args
     */
    protected function raw(array $args): mixed
    {
        $client = $this->redis->connection($this->connectionName())->client();

        if ($client instanceof \Predis\Client) {
            $error = false;
            $result = $client->executeRaw($args, $error);

            if ($error) {
                throw new RuntimeException(is_string($result) ? $result : 'Redis command failed');
            }

            return $result;
        }

        /** @var \Redis $client */
        return $client->rawCommand(...$args);
    }

    /**
     * @param array<int, mixed> $flat
     * @return array<string, string>
     */
    protected function fieldsToMap(array $flat): array
    {
        $map = [];
        $values = array_values($flat);

        for ($i = 0; $i + 1 < count($values); $i += 2) {
            $map[(string) $values[$i]] = (string) $values[$i + 1];
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeMeta(mixed $meta): array
    {
        if (! is_string($meta) || $meta === '') {
            return [];
        }

        $decoded = json_decode($meta, true);

        /** @var array<string, mixed> $result */
        $result = is_array($decoded) ? $decoded : [];

        return $result;
    }

    /**
     * Escape RediSearch TAG special characters in a scope value.
     */
    protected function escapeTag(string $value): string
    {
        // Escape the backslash FIRST — it is RediSearch's own escape character,
        // so a scope containing "\" would otherwise corrupt the query (and could
        // break out of the tag filter across the scope boundary).
        $value = str_replace('\\', '\\\\', $value);

        return preg_replace('/[,.<>{}\[\]"\':;!@#$%^&*()\-+=~| ]/', '\\\\$0', $value) ?? $value;
    }

    protected function connectionName(): ?string
    {
        $name = $this->config['connection'] ?? null;

        return is_string($name) ? $name : null;
    }

    protected function index(): string
    {
        $index = $this->config['index'] ?? 'llm_cache_idx';

        return is_string($index) ? $index : 'llm_cache_idx';
    }

    protected function prefix(): string
    {
        $prefix = $this->config['prefix'] ?? 'llm_cache:';

        return is_string($prefix) ? $prefix : 'llm_cache:';
    }

    protected function algorithm(): string
    {
        $algo = strtoupper((string) ($this->config['algorithm'] ?? 'FLAT'));

        return $algo === 'HNSW' ? 'HNSW' : 'FLAT';
    }
}
