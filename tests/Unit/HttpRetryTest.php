<?php

use Illuminate\Support\Facades\Http;
use Yegoragapov\LlmCache\Providers\OpenAiProvider;

/*
|--------------------------------------------------------------------------
| AppliesHttpOptions — retry budget, transient-vs-permanent classification
|--------------------------------------------------------------------------
| The trait exists to bound outbound embedding calls and retry only transient
| failures (timeouts, 5xx, 429) so a slow/flapping endpoint doesn't hang or
| burn extra calls on a permanent 4xx. Exercised through OpenAiProvider, which
| mixes in the trait via AbstractBearerEmbeddingProvider.
*/

/**
 * @param array<int, float> $vector
 * @return array<string, mixed>
 */
function embeddingResponse(array $vector): array
{
    return ['data' => [['embedding' => $vector]]];
}

/** A valid 1536-dim vector so the dimension guard passes on success. */
function okVector(): array
{
    $v = array_fill(0, 1536, 0.0);
    $v[0] = 0.1;

    return $v;
}

it('retries a transient 500 and then succeeds', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::sequence()
            ->push(['error' => 'server'], 500)
            ->push(embeddingResponse(okVector()), 200),
    ]);

    $provider = new OpenAiProvider(['key' => 'sk-test', 'model' => 'text-embedding-3-small', 'retries' => 2]);

    expect($provider->embed('x'))->toHaveCount(1536);
    Http::assertSentCount(2);
});

it('retries a transient 429 and then succeeds', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::sequence()
            ->push(['error' => 'rate limited'], 429)
            ->push(embeddingResponse(okVector()), 200),
    ]);

    $provider = new OpenAiProvider(['key' => 'sk-test', 'model' => 'text-embedding-3-small', 'retries' => 2]);

    expect($provider->embed('x'))->toHaveCount(1536);
    Http::assertSentCount(2);
});

it('does NOT retry a permanent 400 and fails after one attempt', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::sequence()
            ->push(['error' => 'bad request'], 400)
            // A second (success) response is queued but must never be reached.
            ->push(embeddingResponse(okVector()), 200),
    ]);

    $provider = new OpenAiProvider(['key' => 'sk-test', 'model' => 'text-embedding-3-small', 'retries' => 2]);

    expect(fn () => $provider->embed('x'))->toThrow(RuntimeException::class, 'status 400');
    Http::assertSentCount(1);
});

it('exhausts the retry budget on a persistent 500 then surfaces the failure', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::sequence()
            ->push(['error' => 'server'], 500)
            ->push(['error' => 'server'], 500)
            ->push(['error' => 'server'], 500),
    ]);

    // retries=2 => 3 attempts total (1 + 2).
    $provider = new OpenAiProvider(['key' => 'sk-test', 'model' => 'text-embedding-3-small', 'retries' => 2]);

    expect(fn () => $provider->embed('x'))->toThrow(RuntimeException::class, 'status 500');
    Http::assertSentCount(3);
});

it('makes exactly one attempt when retries are disabled', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::sequence()
            ->push(['error' => 'server'], 500)
            ->push(embeddingResponse(okVector()), 200),
    ]);

    $provider = new OpenAiProvider(['key' => 'sk-test', 'model' => 'text-embedding-3-small', 'retries' => 0]);

    expect(fn () => $provider->embed('x'))->toThrow(RuntimeException::class, 'status 500');
    Http::assertSentCount(1);
});
