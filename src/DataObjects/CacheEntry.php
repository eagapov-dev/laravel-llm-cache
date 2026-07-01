<?php

namespace Yegoragapov\LlmCache\DataObjects;

use Carbon\CarbonInterface;

/**
 * Write payload handed to VectorStore::put().
 *
 * `provider` is intentionally absent: the store knows its own embedding
 * provider from configuration and stamps that column itself. `model` and
 * `promptPreview` are per-call, so they travel here as typed fields (not
 * folded into `meta`) to keep §3's typed columns queryable.
 */
final class CacheEntry
{
    /**
     * @param array<int, float>    $vector
     * @param array<string, mixed> $meta
     * @param CarbonInterface|null $expiresAt Resolved ABSOLUTE moment (now()->add($ttl)), not a duration.
     */
    public function __construct(
        public readonly array $vector,
        public readonly string $response,
        public readonly string $scope,
        public readonly ?CarbonInterface $expiresAt = null,
        public readonly ?string $model = null,
        public readonly ?string $promptPreview = null,
        public readonly array $meta = [],
    ) {
    }
}
