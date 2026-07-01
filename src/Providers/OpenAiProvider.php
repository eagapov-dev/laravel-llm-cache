<?php

namespace Yegoragapov\LlmCache\Providers;

/**
 * OpenAI embeddings (text-embedding-3-small / -large).
 *
 * Request shape (POST https://api.openai.com/v1/embeddings):
 *   headers: Authorization: Bearer <key>
 *   body:    { "model": "<model>", "input": "<text>" }
 * Response shape:
 *   { "data": [ { "embedding": [<float>, ...] } ], ... }
 */
class OpenAiProvider extends AbstractBearerEmbeddingProvider
{
    protected const ENDPOINT = 'https://api.openai.com/v1/embeddings';

    protected const DEFAULT_MODEL = 'text-embedding-3-small';

    protected const MODEL_DIMENSIONS = [
        'text-embedding-3-small' => 1536,
        'text-embedding-3-large' => 3072,
        'text-embedding-ada-002' => 1536,
    ];

    protected const DEFAULT_DIMENSIONS = 1536;

    public function name(): string
    {
        return 'openai';
    }

    protected function endpoint(): string
    {
        return self::ENDPOINT;
    }

    protected function defaultModel(): string
    {
        return self::DEFAULT_MODEL;
    }

    /**
     * @return array<string, int>
     */
    protected function modelDimensions(): array
    {
        return self::MODEL_DIMENSIONS;
    }

    protected function defaultDimensions(): int
    {
        return self::DEFAULT_DIMENSIONS;
    }
}
