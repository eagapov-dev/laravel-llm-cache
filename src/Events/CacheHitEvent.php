<?php

namespace Yegoragapov\LlmCache\Events;

final class CacheHitEvent
{
    public function __construct(
        public readonly string $prompt,
        public readonly float $similarity,
        public readonly string $scope,
        public readonly int|string|null $entryId = null,
    ) {
    }
}
