<?php

namespace Yegoragapov\LlmCache\DataObjects;

/**
 * Result of a successful semantic cache lookup.
 */
final class CacheHit
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $response,
        public readonly float $similarity,
        public readonly array $meta = [],
        public readonly int|string|null $entryId = null,
    ) {
    }
}
