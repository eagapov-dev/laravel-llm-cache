<?php

use Illuminate\Support\Facades\Http;
use Yegoragapov\LlmCache\Providers\HttpProvider;
use Yegoragapov\LlmCache\Providers\NullProvider;
use Yegoragapov\LlmCache\Providers\OpenAiProvider;
use Yegoragapov\LlmCache\Providers\VoyageProvider;

// ---------------------------------------------------------------------------
// NullProvider — deterministic, network-free
// ---------------------------------------------------------------------------

it('null provider is deterministic for the same text', function (): void {
    $provider = new NullProvider(16);

    expect($provider->embed('what are your opening hours?'))
        ->toBe($provider->embed('what are your opening hours?'));
});

it('null provider produces vectors of the configured length', function (): void {
    $provider = new NullProvider(32);

    expect($provider->embed('hello'))->toHaveCount(32);
    expect($provider->dimensions())->toBe(32);
});

it('null provider produces unit-norm vectors', function (): void {
    $provider = new NullProvider(24);

    $vector = $provider->embed('normalize me');
    $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));

    expect($norm)->toBeGreaterThan(0.999)->toBeLessThan(1.001);
});

it('null provider produces distinct vectors for distinct texts', function (): void {
    $provider = new NullProvider(16);

    expect($provider->embed('opening hours'))
        ->not->toBe($provider->embed('closing time'));
});

it('null provider name is null', function (): void {
    expect((new NullProvider(16))->name())->toBe('null');
});

// ---------------------------------------------------------------------------
// OpenAiProvider
// ---------------------------------------------------------------------------

it('openai provider posts to the embeddings endpoint and parses the vector', function (): void {
    $expected = array_fill(0, 1536, 0.0);
    $expected[0] = 0.11;
    $expected[1] = -0.22;

    Http::fake([
        'api.openai.com/*' => Http::response([
            'data' => [['embedding' => $expected]],
        ]),
    ]);

    $provider = new OpenAiProvider(['key' => 'sk-test', 'model' => 'text-embedding-3-small']);

    $vector = $provider->embed('hello world');

    expect($vector)->toHaveCount(1536);
    expect($vector[0])->toBe(0.11);
    expect($vector[1])->toBe(-0.22);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.openai.com/v1/embeddings'
            && $request->hasHeader('Authorization', 'Bearer sk-test')
            && $request['model'] === 'text-embedding-3-small'
            && $request['input'] === 'hello world';
    });
});

it('openai dimensions returns the model constant without any http call', function (): void {
    Http::fake();

    expect((new OpenAiProvider(['model' => 'text-embedding-3-small']))->dimensions())->toBe(1536);
    expect((new OpenAiProvider(['model' => 'text-embedding-3-large']))->dimensions())->toBe(3072);
    expect((new OpenAiProvider([]))->dimensions())->toBe(1536); // default

    Http::assertNothingSent();
});

it('openai name is openai', function (): void {
    expect((new OpenAiProvider([]))->name())->toBe('openai');
});

// ---------------------------------------------------------------------------
// VoyageProvider
// ---------------------------------------------------------------------------

it('voyage provider posts to the embeddings endpoint and parses the vector', function (): void {
    $expected = array_fill(0, 1024, 0.0);
    $expected[0] = 0.5;

    Http::fake([
        'api.voyageai.com/*' => Http::response([
            'data' => [['embedding' => $expected]],
        ]),
    ]);

    $provider = new VoyageProvider(['key' => 'voyage-key', 'model' => 'voyage-3']);

    $vector = $provider->embed('anthropic stack');

    expect($vector)->toHaveCount(1024);
    expect($vector[0])->toBe(0.5);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.voyageai.com/v1/embeddings'
            && $request->hasHeader('Authorization', 'Bearer voyage-key')
            && $request['model'] === 'voyage-3'
            && $request['input'] === 'anthropic stack';
    });
});

it('voyage dimensions returns the model constant without any http call', function (): void {
    Http::fake();

    expect((new VoyageProvider(['model' => 'voyage-3']))->dimensions())->toBe(1024);
    expect((new VoyageProvider([]))->dimensions())->toBe(1024); // default

    Http::assertNothingSent();
});

it('voyage name is voyage', function (): void {
    expect((new VoyageProvider([]))->name())->toBe('voyage');
});

// ---------------------------------------------------------------------------
// HttpProvider
// ---------------------------------------------------------------------------

it('http provider posts input and parses an embedding key', function (): void {
    Http::fake([
        'embeddings.local/*' => Http::response([
            'embedding' => [0.1, 0.2, 0.3, 0.4],
        ]),
    ]);

    $provider = new HttpProvider(['endpoint' => 'https://embeddings.local/embed', 'dimensions' => 4]);

    $vector = $provider->embed('self hosted');

    expect($vector)->toBe([0.1, 0.2, 0.3, 0.4]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://embeddings.local/embed'
            && $request['input'] === 'self hosted';
    });
});

it('http provider parses a flat data array', function (): void {
    Http::fake([
        '*' => Http::response(['data' => [1.0, 2.0, 3.0]]),
    ]);

    $provider = new HttpProvider(['endpoint' => 'https://embeddings.local/embed', 'dimensions' => 3]);

    expect($provider->embed('x'))->toBe([1.0, 2.0, 3.0]);
});

it('http provider parses an openai-compatible data shape', function (): void {
    Http::fake([
        '*' => Http::response(['data' => [['embedding' => [7.0, 8.0]]]]),
    ]);

    $provider = new HttpProvider(['endpoint' => 'https://embeddings.local/embed', 'dimensions' => 2]);

    expect($provider->embed('x'))->toBe([7.0, 8.0]);
});

it('http dimensions returns the configured value without any http call', function (): void {
    Http::fake();

    expect((new HttpProvider(['endpoint' => 'https://x/embed', 'dimensions' => 384]))->dimensions())->toBe(384);

    Http::assertNothingSent();
});

it('http provider throws on a dimension mismatch', function (): void {
    Http::fake([
        '*' => Http::response(['embedding' => [0.1, 0.2]]),
    ]);

    $provider = new HttpProvider(['endpoint' => 'https://x/embed', 'dimensions' => 4]);

    $provider->embed('mismatch');
})->throws(RuntimeException::class);

it('http name is http', function (): void {
    expect((new HttpProvider(['endpoint' => 'https://x', 'dimensions' => 1]))->name())->toBe('http');
});
