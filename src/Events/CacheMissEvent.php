<?php

namespace Yegoragapov\LlmCache\Events;

final class CacheMissEvent
{
    public function __construct(
        public readonly string $prompt,
        public readonly string $scope,
    ) {
    }
}
