<?php

namespace Yegoragapov\LlmCache\Providers;

use LogicException;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;

/**
 * Generic user-configured embedding endpoint (e.g. self-hosted
 * sentence-transformers).
 *
 * STUB (Phase 0). Implemented on `feat/providers` against §4.1.
 */
class HttpProvider implements EmbeddingProvider
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected array $config,
    ) {
    }

    public function embed(string $text): array
    {
        throw new LogicException('HttpProvider::embed() not implemented (Phase 1: feat/providers).');
    }

    public function dimensions(): int
    {
        return (int) ($this->config['dimensions'] ?? 0);
    }

    public function name(): string
    {
        return 'http';
    }
}
