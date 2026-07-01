<?php

namespace Yegoragapov\LlmCache\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;

/**
 * Shared implementation for bearer-token embedding APIs that speak the common
 * OpenAI-style shape:
 *   POST <endpoint>  Authorization: Bearer <key>  { "model": ..., "input": ... }
 *   -> { "data": [ { "embedding": [<float>, ...] } ] }
 *
 * Concrete providers supply only the endpoint, name, default model, and the
 * per-model dimension table; the request/parse/validate flow lives here once.
 */
abstract class AbstractBearerEmbeddingProvider implements EmbeddingProvider
{
    use AppliesHttpOptions;

    /**
     * @param array<string, mixed> $config config keys: key, model
     */
    public function __construct(
        protected array $config,
    ) {
    }

    /** The embeddings endpoint URL. */
    abstract protected function endpoint(): string;

    /** Model used when none is configured. */
    abstract protected function defaultModel(): string;

    /**
     * Known output dimensions per model, so dimensions() never hits the network.
     *
     * @return array<string, int>
     */
    abstract protected function modelDimensions(): array;

    /** Fallback dimension for an unrecognized model. */
    abstract protected function defaultDimensions(): int;

    /**
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        $response = $this->applyHttpOptions(Http::withToken($this->key()))
            ->asJson()
            ->post($this->endpoint(), [
                'model' => $this->model(),
                'input' => $text,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                '%s embeddings request failed with status %d.',
                ucfirst($this->name()),
                $response->status(),
            ));
        }

        /** @var array<int, float>|null $embedding */
        $embedding = $response->json('data.0.embedding');

        if (! is_array($embedding)) {
            throw new RuntimeException(sprintf(
                '%s embeddings response did not contain data.0.embedding.',
                ucfirst($this->name()),
            ));
        }

        $vector = array_map(static fn (mixed $value): float => (float) $value, array_values($embedding));

        $this->assertDimensions($vector);

        return $vector;
    }

    public function dimensions(): int
    {
        return $this->modelDimensions()[$this->model()] ?? $this->defaultDimensions();
    }

    protected function model(): string
    {
        $model = $this->config['model'] ?? null;

        return is_string($model) && $model !== '' ? $model : $this->defaultModel();
    }

    protected function key(): string
    {
        $key = $this->config['key'] ?? null;

        return is_string($key) ? $key : '';
    }

    /**
     * §6 guard: reject a wrongly sized vector (a silently switched model) before
     * it corrupts the store.
     *
     * @param array<int, float> $vector
     */
    protected function assertDimensions(array $vector): void
    {
        $expected = $this->dimensions();

        if (count($vector) !== $expected) {
            throw new RuntimeException(sprintf(
                '%s returned a %d-dimension vector but %d was expected for model "%s".',
                ucfirst($this->name()),
                count($vector),
                $expected,
                $this->model(),
            ));
        }
    }
}
