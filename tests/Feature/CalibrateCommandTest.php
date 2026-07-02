<?php

use Illuminate\Support\Facades\Artisan;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;
use Yegoragapov\LlmCache\Tests\Support\FakeEmbeddingProvider;

/*
|--------------------------------------------------------------------------
| §5.2 llm-cache:calibrate — dataset validation, output, --json
|--------------------------------------------------------------------------
*/

/** Write JSONL content to a fresh temp file and return its path. */
function writeDataset(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'llmcal_').'.jsonl';
    file_put_contents($path, $content);

    return $path;
}

/** Unit vector whose cosine with [1,0] is exactly $s (for crafted similarities). */
function calBVec(float $s): array
{
    return [$s, sqrt(max(0.0, 1.0 - $s * $s))];
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/llmcal_*.jsonl') ?: [] as $f) {
        @unlink($f);
    }
});

it('errors clearly when the dataset file does not exist', function () {
    $code = Artisan::call('llm-cache:calibrate', ['dataset' => '/no/such/file.jsonl']);

    expect($code)->not->toBe(0)
        ->and(Artisan::output())->toContain('not found');
});

it('rejects a line missing the reuse field, naming the line number', function () {
    $path = writeDataset(
        '{"a":"one","b":"two","reuse":true}'."\n".
        '{"a":"three","b":"four"}'."\n"
    );

    $code = Artisan::call('llm-cache:calibrate', ['dataset' => $path]);
    $out = Artisan::output();

    expect($code)->not->toBe(0)
        ->and($out)->toContain('line 2')
        ->and($out)->toContain('reuse');
});

it('rejects a line with an empty prompt, naming the line number', function () {
    $path = writeDataset(
        '{"a":"one","b":"two","reuse":true}'."\n".
        '{"a":"","b":"four","reuse":false}'."\n"
    );

    $code = Artisan::call('llm-cache:calibrate', ['dataset' => $path]);

    expect($code)->not->toBe(0)
        ->and(Artisan::output())->toContain('line 2');
});

it('errors when the dataset has no valid pairs', function () {
    $path = writeDataset("\n   \n");

    $code = Artisan::call('llm-cache:calibrate', ['dataset' => $path]);

    expect($code)->not->toBe(0)
        ->and(Artisan::output())->toContain('no pairs to calibrate');
});

it('rejects an inverted sweep range before doing any work', function () {
    $path = writeDataset('{"a":"one","b":"two","reuse":true}'."\n");

    $code = Artisan::call('llm-cache:calibrate', [
        'dataset' => $path,
        '--min' => '0.99',
        '--max' => '0.80',
    ]);

    expect($code)->not->toBe(0);
});

it('aborts on a malformed line under the default strict mode', function () {
    $path = writeDataset(
        '{"a":"one","b":"two","reuse":true}'."\n".
        'this is not json'."\n"
    );

    $code = Artisan::call('llm-cache:calibrate', ['dataset' => $path]);

    expect($code)->not->toBe(0)
        ->and(Artisan::output())->toContain('line 2');
});

it('skips a malformed line under --strict=false and notes how many were skipped', function () {
    // Prime the null provider is fine here; we only assert it runs and reports.
    $path = writeDataset(
        '{"a":"one","b":"two","reuse":true}'."\n".
        'garbage'."\n".
        '{"a":"three","b":"four","reuse":false}'."\n"
    );

    $code = Artisan::call('llm-cache:calibrate', ['dataset' => $path, '--strict' => 'false']);

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('skipped');
});

it('emits valid JSON with false_hit_pairs keyed by threshold under --json', function () {
    // Craft an embedding provider so the reuse=false pair is a genuine false hit
    // (cosine 0.96) that shows up in false_hit_pairs at low thresholds.
    $fake = (new FakeEmbeddingProvider(2))
        ->set('cancel', [1.0, 0.0])->set('renew', calBVec(0.96))
        ->set('hours', [1.0, 0.0])->set('open', calBVec(0.985));
    app()->instance(EmbeddingProvider::class, $fake);

    $path = writeDataset(
        '{"a":"hours","b":"open","reuse":true}'."\n".
        '{"a":"cancel","b":"renew","reuse":false,"note":"negation"}'."\n"
    );

    $code = Artisan::call('llm-cache:calibrate', ['dataset' => $path, '--json' => true]);
    $out = Artisan::output();
    $data = json_decode($out, true);

    expect($code)->toBe(0)
        ->and($data)->toBeArray()
        ->and($out)->not->toContain('threshold  false_hits') // no table header text
        ->and($data['dataset_size'])->toBe(2)
        ->and($data['positives'])->toBe(1)
        ->and($data['negatives'])->toBe(1)
        ->and($data['objective'])->toBe('cost_weighted')
        ->and($data['thresholds'])->toBeArray()
        ->and($data['false_hit_pairs'])->toBeArray();

    // The cancel/renew pair (sim 0.96) is a false hit at 0.90; assert it's listed.
    expect($data['false_hit_pairs']['0.90'][0]['a'])->toBe('cancel')
        ->and($data['false_hit_pairs']['0.90'][0]['note'])->toBe('negation');
});

it('renders a human table with a recommended threshold by default', function () {
    $path = writeDataset(
        '{"a":"one","b":"two","reuse":true}'."\n".
        '{"a":"three","b":"four","reuse":false}'."\n"
    );

    $code = Artisan::call('llm-cache:calibrate', ['dataset' => $path]);
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain('threshold')
        ->and($out)->toContain('false_hits')
        ->and($out)->toContain('Recommended');
});
