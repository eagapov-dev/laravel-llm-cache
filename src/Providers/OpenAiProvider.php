<?php

namespace Yegoragapov\LlmCache\Providers;

use LogicException;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;

/**
 * OpenAI embeddings (text-embedding-3-small / -large).
 *
 * STUB (Phase 0). Implemented on `feat/providers` against §4.1.
 */
class OpenAiProvider implements EmbeddingProvider
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
        throw new LogicException('OpenAiProvider::embed() not implemented (Phase 1: feat/providers).');
    }

    public function dimensions(): int
    {
        throw new LogicException('OpenAiProvider::dimensions() not implemented (Phase 1: feat/providers).');
    }

    public function name(): string
    {
        return 'openai';
    }
}
