<?php

namespace Yegoragapov\LlmCache\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;

/**
 * OpenAI embeddings (text-embedding-3-small / -large).
 *
 * Request shape (POST https://api.openai.com/v1/embeddings):
 *   headers: Authorization: Bearer <key>
 *   body:    { "model": "<model>", "input": "<text>" }
 * Response shape:
 *   { "data": [ { "embedding": [<float>, ...] } ], ... }
 */
class OpenAiProvider implements EmbeddingProvider
{
    protected const ENDPOINT = 'https://api.openai.com/v1/embeddings';

    protected const DEFAULT_MODEL = 'text-embedding-3-small';

    /**
     * Known output dimensions per model, so dimensions() never hits the network.
     */
    protected const MODEL_DIMENSIONS = [
        'text-embedding-3-small' => 1536,
        'text-embedding-3-large' => 3072,
        'text-embedding-ada-002' => 1536,
    ];

    protected const DEFAULT_DIMENSIONS = 1536;

    /**
     * @param array<string, mixed> $config config keys: key, model
     */
    public function __construct(
        protected array $config,
    ) {
    }

    /**
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        $response = Http::withToken($this->key())
            ->asJson()
            ->post(self::ENDPOINT, [
                'model' => $this->model(),
                'input' => $text,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "OpenAI embeddings request failed with status {$response->status()}."
            );
        }

        /** @var array<int, float>|null $embedding */
        $embedding = $response->json('data.0.embedding');

        if (! is_array($embedding)) {
            throw new RuntimeException('OpenAI embeddings response did not contain data.0.embedding.');
        }

        $vector = array_map(static fn (mixed $value): float => (float) $value, array_values($embedding));

        $this->assertDimensions($vector);

        return $vector;
    }

    public function dimensions(): int
    {
        return self::MODEL_DIMENSIONS[$this->model()] ?? self::DEFAULT_DIMENSIONS;
    }

    public function name(): string
    {
        return 'openai';
    }

    protected function model(): string
    {
        $model = $this->config['model'] ?? null;

        return is_string($model) && $model !== '' ? $model : self::DEFAULT_MODEL;
    }

    protected function key(): string
    {
        $key = $this->config['key'] ?? null;

        return is_string($key) ? $key : '';
    }

    /**
     * §6 guard: a provider silently switching model would return a differently
     * sized vector and corrupt the store. Detect it before returning.
     *
     * @param array<int, float> $vector
     */
    protected function assertDimensions(array $vector): void
    {
        $expected = $this->dimensions();

        if (count($vector) !== $expected) {
            throw new RuntimeException(sprintf(
                'OpenAI returned a %d-dimension vector but %d was expected for model "%s".',
                count($vector),
                $expected,
                $this->model(),
            ));
        }
    }
}
