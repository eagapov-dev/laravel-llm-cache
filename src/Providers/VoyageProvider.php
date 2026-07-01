<?php

namespace Yegoragapov\LlmCache\Providers;

/**
 * Voyage AI embeddings (recommended for Anthropic-centric stacks; Anthropic
 * ships no first-party embeddings).
 *
 * Request shape (POST https://api.voyageai.com/v1/embeddings):
 *   headers: Authorization: Bearer <key>
 *   body:    { "model": "<model>", "input": "<text>" }
 * Response shape:
 *   { "data": [ { "embedding": [<float>, ...] } ], ... }
 */
class VoyageProvider extends AbstractBearerEmbeddingProvider
{
    protected const ENDPOINT = 'https://api.voyageai.com/v1/embeddings';

    protected const DEFAULT_MODEL = 'voyage-3';

    protected const MODEL_DIMENSIONS = [
        'voyage-3' => 1024,
        'voyage-3-lite' => 512,
        'voyage-3-large' => 1024,
        'voyage-2' => 1024,
    ];

    protected const DEFAULT_DIMENSIONS = 1024;

    public function name(): string
    {
        return 'voyage';
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
