<?php

use Yegoragapov\LlmCache\Stores\RedisStore;

/*
|--------------------------------------------------------------------------
| RedisStore — server-free unit coverage (vector serialization)
|--------------------------------------------------------------------------
| Behavioural coverage runs through the §5.1 dataset against a live Redis Stack
| (LLM_CACHE_TEST_REDIS=1). These assert the FLOAT32 blob encoding without a
| server.
*/

it('serializes a vector to a float32 little-endian blob', function () {
    $store = new RedisStore(app('redis'), ['index' => 'x', 'prefix' => 'y:'], 3, 'fake');

    $blob = $store->vectorBlob([1.0, 0.5, 0.0]);

    expect(strlen($blob))->toBe(12) // 3 × 4 bytes
        ->and(array_values((array) unpack('g*', $blob)))->toBe([1.0, 0.5, 0.0]);
});

it('rejects a vector whose length does not match the configured dimension', function () {
    $store = new RedisStore(app('redis'), [], 3, 'fake');

    expect(fn () => $store->vectorBlob([1.0, 2.0]))->toThrow(RuntimeException::class);
});

/*
|--------------------------------------------------------------------------
| escapeTag — RediSearch TAG escaping (scope-isolation safety, server-free)
|--------------------------------------------------------------------------
| A scope becomes a TAG filter (@scope:{...}); an unescaped special character
| could mis-tokenize the filter or break out of the tag across the scope
| boundary. The method is protected, so we reach it via reflection.
*/

function escapeTag(string $value): string
{
    $store = new RedisStore(app('redis'), [], 3, 'fake');
    $method = new ReflectionMethod(RedisStore::class, 'escapeTag');
    $method->setAccessible(true);

    return $method->invoke($store, $value);
}

it('escapes the backslash first so it cannot corrupt subsequent escapes', function () {
    // A lone backslash must become exactly two backslashes — escaped once, and
    // not re-consumed by the punctuation pass.
    expect(escapeTag('a\\b'))->toBe('a\\\\b');
});

it('escapes tag structural and separator characters', function () {
    expect(escapeTag('user:42'))->toBe('user\\:42');
    expect(escapeTag('a{b}c'))->toBe('a\\{b\\}c');
    expect(escapeTag('a|b'))->toBe('a\\|b');
    expect(escapeTag('a b'))->toBe('a\\ b'); // space is a token separator
});

it('escapes wildcard characters so a scope cannot widen a match', function () {
    expect(escapeTag('user:*'))->toBe('user\\:\\*');
    expect(escapeTag('user:?'))->toBe('user\\:\\?');
});

it('escapes control whitespace (tab, CR, LF)', function () {
    expect(escapeTag("a\tb"))->toBe('a\\	b');
    expect(escapeTag("a\nb"))->toBe("a\\\nb");
    expect(escapeTag("a\rb"))->toBe("a\\\rb");
});

it('leaves a plain alphanumeric scope untouched', function () {
    expect(escapeTag('tenant42'))->toBe('tenant42');
});
