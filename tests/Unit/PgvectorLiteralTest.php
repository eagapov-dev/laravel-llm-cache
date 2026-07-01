<?php

use Yegoragapov\LlmCache\Stores\PgvectorStore;

/*
|--------------------------------------------------------------------------
| Vector-literal serialization (no live DB required)
|--------------------------------------------------------------------------
*/

it('serializes a vector to a pgvector text literal', function () {
    expect(PgvectorStore::vectorLiteral([1.0, 0.0, -0.5]))->toBe('[1,0,-0.5]');
});

it('serializes an empty vector', function () {
    expect(PgvectorStore::vectorLiteral([]))->toBe('[]');
});

it('uses a dot decimal separator regardless of value magnitude', function () {
    $literal = PgvectorStore::vectorLiteral([0.125, 3.5]);

    expect($literal)->toBe('[0.125,3.5]')
        ->and($literal)->not->toContain('e')
        ->and($literal)->not->toContain('E');
});
