<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Yegoragapov\LlmCache\Events\CacheHitEvent;
use Yegoragapov\LlmCache\Events\CacheMissEvent;
use Yegoragapov\LlmCache\Listeners\RecordCacheEvent;
use Yegoragapov\LlmCache\LlmCacheServiceProvider;

/*
|--------------------------------------------------------------------------
| §8.1 Event recording listener
|--------------------------------------------------------------------------
*/

function eventsTable(): void
{
    Schema::dropIfExists('llm_cache_events');
    Schema::create('llm_cache_events', function (Blueprint $t): void {
        $t->bigIncrements('id');
        $t->string('type');
        $t->string('scope');
        $t->double('similarity')->nullable();
        $t->timestamp('created_at')->nullable();
    });
}

it('records a hit event as a row with its scope and similarity', function () {
    eventsTable();

    app(RecordCacheEvent::class)->handleHit(new CacheHitEvent('opening hours?', 0.97, 'user:1', 42));

    $row = DB::table('llm_cache_events')->first();
    expect($row->type)->toBe('hit')
        ->and($row->scope)->toBe('user:1')
        ->and((float) $row->similarity)->toBe(0.97);
});

it('records a miss event with null similarity', function () {
    eventsTable();

    app(RecordCacheEvent::class)->handleMiss(new CacheMissEvent('opening hours?', 'global'));

    $row = DB::table('llm_cache_events')->first();
    expect($row->type)->toBe('miss')
        ->and($row->scope)->toBe('global')
        ->and($row->similarity)->toBeNull();
});

it('is wired to the dispatcher when stats.record is enabled', function () {
    config()->set('llm-cache.stats.record', true);
    eventsTable();

    (new LlmCacheServiceProvider(app()))->boot();

    event(new CacheMissEvent('p', 'global'));
    event(new CacheHitEvent('p', 0.99, 'global', 1));

    expect(DB::table('llm_cache_events')->count())->toBe(2);
});

it('never throws when the events table is absent (fail-open)', function () {
    config()->set('llm-cache.stats.record', true);
    Schema::dropIfExists('llm_cache_events');

    app(RecordCacheEvent::class)->handleMiss(new CacheMissEvent('p', 'global'));

    expect(true)->toBeTrue();
});
