<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| §8.3 Acceptance — llm-cache:stats
|--------------------------------------------------------------------------
| Event history is driver-agnostic (no vector column), so these run on the
| default sqlite test connection.
*/

function makeEventsTable(): void
{
    Schema::dropIfExists('llm_cache_events');
    Schema::create('llm_cache_events', function (Blueprint $t): void {
        $t->bigIncrements('id');
        $t->string('type')->index();
        $t->string('scope')->index();
        $t->double('similarity')->nullable();
        $t->timestamp('created_at')->nullable()->index();
    });
}

function insertEvent(string $type, string $scope = 'global', ?float $similarity = null, ?string $createdAt = null): void
{
    DB::table('llm_cache_events')->insert([
        'type' => $type,
        'scope' => $scope,
        'similarity' => $similarity,
        'created_at' => $createdAt ?? Carbon::now()->toDateTimeString(),
    ]);
}

beforeEach(function () {
    config()->set('llm-cache.stats.record', true);
    config()->set('llm-cache.stats.avg_tokens_per_generation', 500);
});

it('summarises hits, misses, hit rate and estimated tokens saved as JSON', function () {
    makeEventsTable();
    insertEvent('hit', 'global', 0.97);
    insertEvent('hit', 'user:1', 0.99);
    insertEvent('hit', 'global', 0.96);
    insertEvent('miss', 'global');

    $code = Artisan::call('llm-cache:stats', ['--json' => true]);
    $data = json_decode(Artisan::output(), true);

    expect($code)->toBe(0)
        ->and($data['total'])->toBe(4)
        ->and($data['hits'])->toBe(3)
        ->and($data['misses'])->toBe(1)
        ->and(round((float) $data['hit_rate'], 2))->toBe(0.75)
        ->and($data['estimated_tokens_saved'])->toBe(1500);
});

it('renders a human-readable summary table without --json', function () {
    makeEventsTable();
    insertEvent('hit', 'global', 0.97);
    insertEvent('miss', 'global');

    $code = Artisan::call('llm-cache:stats');
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain('Hit rate')
        ->and($out)->toContain('Est. tokens saved');
});

it('reports zero calls and a zero hit rate on an empty table without error', function () {
    makeEventsTable();

    $code = Artisan::call('llm-cache:stats', ['--json' => true]);
    $data = json_decode(Artisan::output(), true);

    expect($code)->toBe(0)
        ->and($data['total'])->toBe(0)
        ->and($data['hits'])->toBe(0)
        ->and((float) $data['hit_rate'])->toBe(0.0)
        ->and($data['estimated_tokens_saved'])->toBe(0);
});

it('counts only events within the --days window', function () {
    makeEventsTable();
    insertEvent('hit', 'global', 0.97, Carbon::now()->subDays(10)->toDateTimeString());
    insertEvent('hit', 'global', 0.98, Carbon::now()->subHours(2)->toDateTimeString());
    insertEvent('miss', 'global', null, Carbon::now()->subHours(1)->toDateTimeString());

    $code = Artisan::call('llm-cache:stats', ['--json' => true, '--days' => 1]);
    $data = json_decode(Artisan::output(), true);

    expect($code)->toBe(0)
        ->and($data['total'])->toBe(2)
        ->and($data['hits'])->toBe(1)
        ->and($data['misses'])->toBe(1);
});

it('prints guidance and exits 0 when the events table is absent', function () {
    Schema::dropIfExists('llm_cache_events');

    $code = Artisan::call('llm-cache:stats');

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('stats.record');
});
