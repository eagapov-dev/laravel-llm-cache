<?php

namespace Yegoragapov\LlmCache\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;

/**
 * Generic user-configured embedding endpoint (e.g. self-hosted
 * sentence-transformers).
 *
 * Request shape (POST to config['endpoint']):
 *   body: { "input": "<text>" }
 * Response shape — lenient, any of:
 *   { "embedding": [<float>, ...] }
 *   { "data": [<float>, ...] }
 *   { "data": [ { "embedding": [<float>, ...] } ] }   (OpenAI-compatible)
 */
class HttpProvider implements EmbeddingProvider
{
    /**
     * @param array<string, mixed> $config config keys: endpoint, dimensions
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
        $endpoint = $this->config['endpoint'] ?? null;

        if (! is_string($endpoint) || $endpoint === '') {
            throw new RuntimeException('HttpProvider requires a non-empty "endpoint" config value.');
        }

        $response = Http::asJson()->post($endpoint, [
            'input' => $text,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "HTTP embeddings request failed with status {$response->status()}."
            );
        }

        $vector = $this->extractVector($response->json());

        if ($vector === null) {
            throw new RuntimeException(
                'HTTP embeddings response did not contain an "embedding" or "data" vector.'
            );
        }

        $this->assertDimensions($vector);

        return $vector;
    }

    public function dimensions(): int
    {
        return (int) ($this->config['dimensions'] ?? 0);
    }

    public function name(): string
    {
        return 'http';
    }

    /**
     * Leniently pull a float vector out of a variety of response shapes.
     *
     * @param mixed $payload
     * @return array<int, float>|null
     */
    protected function extractVector(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        // { "embedding": [...] }
        if (isset($payload['embedding']) && is_array($payload['embedding'])) {
            return $this->toFloatVector($payload['embedding']);
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            $data = $payload['data'];

            // OpenAI-compatible: { "data": [ { "embedding": [...] } ] }
            if (isset($data[0]) && is_array($data[0]) && isset($data[0]['embedding']) && is_array($data[0]['embedding'])) {
                return $this->toFloatVector($data[0]['embedding']);
            }

            // Flat: { "data": [<float>, ...] }
            return $this->toFloatVector($data);
        }

        return null;
    }

    /**
     * @param array<mixed> $values
     * @return array<int, float>
     */
    protected function toFloatVector(array $values): array
    {
        return array_map(static fn (mixed $value): float => (float) $value, array_values($values));
    }

    /**
     * §6 guard: if a dimension is configured, reject a mismatched vector before
     * it corrupts the store. A zero/unset dimension disables the check.
     *
     * @param array<int, float> $vector
     */
    protected function assertDimensions(array $vector): void
    {
        $expected = $this->dimensions();

        if ($expected > 0 && count($vector) !== $expected) {
            throw new RuntimeException(sprintf(
                'HTTP endpoint returned a %d-dimension vector but %d was configured.',
                count($vector),
                $expected,
            ));
        }
    }
}
