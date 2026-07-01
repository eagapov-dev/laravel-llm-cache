<?php

namespace Yegoragapov\LlmCache\Support;

use Carbon\CarbonInterface;

/**
 * Internal, mutable row held by the in-memory ArrayStore.
 *
 * This is the shape returned by ArrayStore::entries() for test support:
 * each object exposes public fields, notably the incrementing `id` and the
 * `hits` counter that search() bumps on a match.
 */
final class StoredEntry
{
    /**
     * @param array<int, float>    $vector
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly int $id,
        public readonly array $vector,
        public readonly string $response,
        public readonly string $scope,
        public readonly ?CarbonInterface $expiresAt,
        public readonly ?string $model,
        public readonly ?string $promptPreview,
        public readonly array $meta,
        public readonly string $provider,
        public int $hits = 0,
    ) {
    }

    /**
     * An entry with a non-null expiry in the past is stale and must not match.
     */
    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->isPast();
    }
}
