<?php

namespace Yegoragapov\LlmCache\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;

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
class VoyageProvider implements EmbeddingProvider
{
    protected const ENDPOINT = 'https://api.voyageai.com/v1/embeddings';

    protected const DEFAULT_MODEL = 'voyage-3';

    /**
     * Known output dimensions per model, so dimensions() never hits the network.
     */
    protected const MODEL_DIMENSIONS = [
        'voyage-3' => 1024,
        'voyage-3-lite' => 512,
        'voyage-3-large' => 1024,
        'voyage-2' => 1024,
    ];

    protected const DEFAULT_DIMENSIONS = 1024;

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
                "Voyage embeddings request failed with status {$response->status()}."
            );
        }

        /** @var array<int, float>|null $embedding */
        $embedding = $response->json('data.0.embedding');

        if (! is_array($embedding)) {
            throw new RuntimeException('Voyage embeddings response did not contain data.0.embedding.');
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
        return 'voyage';
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
     * §6 guard: reject a wrongly sized vector before it corrupts the store.
     *
     * @param array<int, float> $vector
     */
    protected function assertDimensions(array $vector): void
    {
        $expected = $this->dimensions();

        if (count($vector) !== $expected) {
            throw new RuntimeException(sprintf(
                'Voyage returned a %d-dimension vector but %d was expected for model "%s".',
                count($vector),
                $expected,
                $this->model(),
            ));
        }
    }
}
