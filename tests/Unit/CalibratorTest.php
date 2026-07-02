<?php

use Yegoragapov\LlmCache\Calibration\Calibrator;
use Yegoragapov\LlmCache\Calibration\ThresholdRow;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;
use Yegoragapov\LlmCache\DataObjects\PromptPair;
use Yegoragapov\LlmCache\Exceptions\DimensionMismatchException;
use Yegoragapov\LlmCache\Tests\Support\FakeEmbeddingProvider;

/*
|--------------------------------------------------------------------------
| §5.1 Calibrator — deterministic confusion metrics & recommendation
|--------------------------------------------------------------------------
| Similarities are crafted exactly: for a target cosine s, pair A=[1,0] with
| B=[s, sqrt(1-s^2)] (both unit vectors) gives cosine(A,B) = s. The null/fake
| provider returns these fixed vectors, so no network and exact similarities.
*/

/** Unit vector whose cosine with [1,0] is exactly $s. */
function bVec(float $s): array
{
    return [$s, sqrt(max(0.0, 1.0 - $s * $s))];
}

/**
 * Build a fake provider + a list of pairs with the given [reuse, similarity]
 * specs. Each pair gets distinct prompt strings primed to the crafted vectors.
 *
 * @param array<int, array{bool, float}> $specs
 * @return array{FakeEmbeddingProvider, list<PromptPair>}
 */
function fixture(array $specs): array
{
    $provider = new FakeEmbeddingProvider(2);
    $pairs = [];

    foreach ($specs as $i => [$reuse, $sim]) {
        $a = "p{$i}a";
        $b = "p{$i}b";
        $provider->set($a, [1.0, 0.0])->set($b, bVec($sim));
        $pairs[] = new PromptPair(a: $a, b: $b, reuse: $reuse);
    }

    return [$provider, $pairs];
}

/** Find the swept row whose threshold rounds to $t. */
function rowAt(array $rows, float $t): ThresholdRow
{
    foreach ($rows as $row) {
        if (abs($row->threshold - $t) < 1e-9) {
            return $row;
        }
    }

    throw new RuntimeException("no row at threshold {$t}");
}

it('computes exact false-hit / false-miss / TP / TN counts per threshold', function () {
    // pair0: reuse=true  sim 0.98   pair1: reuse=false sim 0.94   pair2: reuse=true sim 0.92
    [$provider, $pairs] = fixture([[true, 0.98], [false, 0.94], [true, 0.92]]);

    $result = (new Calibrator($provider))->run($pairs, 0.80, 0.99, 0.01);

    // At 0.93: 0.94 (reuse=false) is a false hit; 0.92 (reuse=true) a false miss.
    $r93 = rowAt($result->thresholds, 0.93);
    expect($r93->falseHits)->toBe(1)
        ->and($r93->falseMisses)->toBe(1)
        ->and($r93->truePositives)->toBe(1)
        ->and($r93->trueNegatives)->toBe(0);

    // At 0.95: 0.94 drops below → true negative; 0.92 still a false miss.
    $r95 = rowAt($result->thresholds, 0.95);
    expect($r95->falseHits)->toBe(0)
        ->and($r95->falseMisses)->toBe(1)
        ->and($r95->truePositives)->toBe(1)
        ->and($r95->trueNegatives)->toBe(1);
});

it('classifies a reuse=false pair at sim 0.94 as false-hit below and true-negative above', function () {
    [$provider, $pairs] = fixture([[false, 0.94]]);

    $result = (new Calibrator($provider))->run($pairs, 0.80, 0.99, 0.01);

    expect(rowAt($result->thresholds, 0.93)->falseHits)->toBe(1)
        ->and(rowAt($result->thresholds, 0.95)->trueNegatives)->toBe(1)
        ->and(rowAt($result->thresholds, 0.95)->falseHits)->toBe(0);
});

it('classifies a reuse=true pair at sim 0.92 as false-miss above and true-positive below', function () {
    [$provider, $pairs] = fixture([[true, 0.92]]);

    $result = (new Calibrator($provider))->run($pairs, 0.80, 0.99, 0.01);

    expect(rowAt($result->thresholds, 0.95)->falseMisses)->toBe(1)
        ->and(rowAt($result->thresholds, 0.90)->truePositives)->toBe(1)
        ->and(rowAt($result->thresholds, 0.90)->falseMisses)->toBe(0);
});

it('treats similarity exactly equal to the threshold as a hit (>=), matching the store', function () {
    [$provider, $pairs] = fixture([[true, 0.95]]);

    $result = (new Calibrator($provider))->run($pairs, 0.80, 0.99, 0.01);

    // sim == 0.95 at threshold 0.95 must count as a hit → true positive, not a miss.
    expect(rowAt($result->thresholds, 0.95)->truePositives)->toBe(1)
        ->and(rowAt($result->thresholds, 0.95)->falseMisses)->toBe(0);
});

it('computes precision, recall, f1 and cost for a threshold', function () {
    // 2 reuse=true (both hit at 0.90), 1 reuse=false hit at 0.90 → TP=2, FH=1, FM=0.
    [$provider, $pairs] = fixture([[true, 0.98], [true, 0.97], [false, 0.96]]);

    $result = (new Calibrator($provider))->run($pairs, 0.80, 0.99, 0.01, 'cost_weighted', 5.0);

    $r = rowAt($result->thresholds, 0.90);
    expect($r->truePositives)->toBe(2)
        ->and($r->falseHits)->toBe(1)
        ->and($r->falseMisses)->toBe(0)
        ->and($r->precision)->toEqualWithDelta(2 / 3, 1e-9)   // TP/(TP+FH)
        ->and($r->recall)->toEqualWithDelta(1.0, 1e-9)         // TP/(TP+FM)
        ->and($r->f1)->toEqualWithDelta(2 * (2 / 3) / (1 + 2 / 3), 1e-9)
        ->and($r->cost)->toEqualWithDelta(5.0 * 1 + 0, 1e-9);  // penalty·FH + FM
});

it('leaves precision undefined (null) when a threshold predicts no hits', function () {
    // A single reuse=false pair at low sim: at a high threshold nothing hits, so
    // TP+FH = 0 and precision is undefined rather than NaN/0.
    [$provider, $pairs] = fixture([[false, 0.10]]);

    $result = (new Calibrator($provider))->run($pairs, 0.80, 0.99, 0.01);

    expect(rowAt($result->thresholds, 0.95)->precision)->toBeNull();
});

it('embeds each distinct prompt exactly once across all pairs (memoized)', function () {
    $inner = (new FakeEmbeddingProvider(2))
        ->set('shared', [1.0, 0.0])
        ->set('y1', bVec(0.9))
        ->set('y2', bVec(0.8));

    $counting = new class($inner) implements EmbeddingProvider {
        /** @var array<string, int> */
        public array $calls = [];

        public function __construct(private EmbeddingProvider $inner) {}

        public function embed(string $text): array
        {
            $this->calls[$text] = ($this->calls[$text] ?? 0) + 1;

            return $this->inner->embed($text);
        }

        public function dimensions(): int
        {
            return $this->inner->dimensions();
        }

        public function name(): string
        {
            return 'counting';
        }
    };

    // 'shared' appears in both pairs.
    $pairs = [
        new PromptPair(a: 'shared', b: 'y1', reuse: true),
        new PromptPair(a: 'shared', b: 'y2', reuse: false),
    ];

    (new Calibrator($counting))->run($pairs, 0.80, 0.99, 0.01);

    expect($counting->calls['shared'])->toBe(1)
        ->and($counting->calls['y1'])->toBe(1)
        ->and($counting->calls['y2'])->toBe(1);
});

it('reports dataset size, positives and negatives', function () {
    [$provider, $pairs] = fixture([[true, 0.98], [false, 0.94], [true, 0.92]]);

    $result = (new Calibrator($provider))->run($pairs, 0.80, 0.99, 0.01);

    expect($result->datasetSize)->toBe(3)
        ->and($result->positives)->toBe(2)
        ->and($result->negatives)->toBe(1);
});

it('records the offending pairs per threshold in false_hit_pairs', function () {
    [$provider, $pairs] = fixture([[false, 0.94]]);

    $result = (new Calibrator($provider))->run($pairs, 0.80, 0.99, 0.01);

    // At 0.93 the reuse=false pair is a false hit and should be listed.
    expect($result->falseHitPairs['0.93'] ?? null)->not->toBeNull()
        ->and($result->falseHitPairs['0.93'][0]['a'])->toBe('p0a')
        ->and($result->falseHitPairs['0.93'][0]['similarity'])->toEqualWithDelta(0.94, 1e-9);

    // At 0.95 it is no longer a false hit → not listed.
    expect($result->falseHitPairs['0.95'] ?? [])->toBe([]);
});

// --- Recommendation objectives (pure, synthetic rows) ---------------------

/** @param array{float,int,int} ...$rows [threshold, falseHits, falseMisses] */
function rows(array ...$rows): array
{
    return array_map(
        fn (array $r) => new ThresholdRow(
            threshold: $r[0],
            falseHits: $r[1],
            falseMisses: $r[2],
            truePositives: 0,
            trueNegatives: 0,
            precision: null,
            recall: null,
            f1: null,
            cost: 0.0,
        ),
        $rows,
    );
}

it('recommends the lower-cost threshold under the default cost_weighted objective', function () {
    // X: 2 FH + 4 FM → cost 2·5+4 = 14 ; Y: 0 FH + 20 FM → cost 20. X wins.
    $rows = rows([0.90, 2, 4], [0.97, 0, 20]);

    expect(Calibrator::recommend($rows, 'cost_weighted', 5.0))->toBe(0.90);
});

it('shifts the cost_weighted recommendation as the false-hit penalty changes', function () {
    // P: 3 FH, 0 FM ; Q: 0 FH, 10 FM.
    $rows = rows([0.90, 3, 0], [0.97, 0, 10]);

    // penalty 1 → P cost 3 < Q cost 10 → P.
    expect(Calibrator::recommend($rows, 'cost_weighted', 1.0))->toBe(0.90);
    // penalty 5 → P cost 15 > Q cost 10 → Q.
    expect(Calibrator::recommend($rows, 'cost_weighted', 5.0))->toBe(0.97);
});

it('breaks a min_false_hits tie toward the lower threshold (max reuse)', function () {
    $rows = rows([0.95, 0, 5], [0.97, 0, 12]);

    expect(Calibrator::recommend($rows, 'min_false_hits', 5.0))->toBe(0.95);
});

it('maximizes precision, breaking ties by higher recall', function () {
    $a = new ThresholdRow(0.93, 1, 1, 9, 5, precision: 0.9, recall: 0.90, f1: 0.9, cost: 0.0);
    $b = new ThresholdRow(0.95, 0, 3, 9, 6, precision: 1.0, recall: 0.75, f1: 0.85, cost: 0.0);
    $c = new ThresholdRow(0.97, 0, 1, 9, 6, precision: 1.0, recall: 0.90, f1: 0.94, cost: 0.0);

    // b and c tie on precision 1.0; c has higher recall → c wins.
    expect(Calibrator::recommend([$a, $b, $c], 'max_precision', 5.0))->toBe(0.97);
});

// --- Validation & failure modes -------------------------------------------

it('rejects an inverted or non-positive sweep before embedding anything', function () {
    $provider = new class implements EmbeddingProvider {
        public int $calls = 0;

        public function embed(string $text): array
        {
            $this->calls++;

            return [1.0, 0.0];
        }

        public function dimensions(): int
        {
            return 2;
        }

        public function name(): string
        {
            return 'guard';
        }
    };

    $pairs = [new PromptPair(a: 'a', b: 'b', reuse: true)];
    $calibrator = new Calibrator($provider);

    expect(fn () => $calibrator->run($pairs, 0.99, 0.80, 0.01))->toThrow(InvalidArgumentException::class);
    expect(fn () => $calibrator->run($pairs, 0.80, 0.99, 0.0))->toThrow(InvalidArgumentException::class);
    expect($provider->calls)->toBe(0);
});

it('rejects an empty pair set with a clear error', function () {
    $provider = new FakeEmbeddingProvider(2);

    expect(fn () => (new Calibrator($provider))->run([], 0.80, 0.99, 0.01))
        ->toThrow(InvalidArgumentException::class, 'no pairs to calibrate');
});

it('surfaces a dimension mismatch when the provider returns an unexpected length', function () {
    $provider = new class implements EmbeddingProvider {
        public function embed(string $text): array
        {
            return $text === 'bad' ? [1.0, 0.0, 0.0] : [1.0, 0.0]; // 3 vs configured 2
        }

        public function dimensions(): int
        {
            return 2;
        }

        public function name(): string
        {
            return 'wrongdim';
        }
    };

    $pairs = [new PromptPair(a: 'ok', b: 'bad', reuse: true)];

    expect(fn () => (new Calibrator($provider))->run($pairs, 0.80, 0.99, 0.01))
        ->toThrow(DimensionMismatchException::class);
});

it('aborts naming the prompt when the provider fails mid-run (fail-closed)', function () {
    $provider = new class implements EmbeddingProvider {
        public function embed(string $text): array
        {
            if ($text === 'boom') {
                throw new RuntimeException('embed exploded');
            }

            return [1.0, 0.0];
        }

        public function dimensions(): int
        {
            return 2;
        }

        public function name(): string
        {
            return 'flaky';
        }
    };

    $pairs = [new PromptPair(a: 'fine', b: 'boom', reuse: true)];

    expect(fn () => (new Calibrator($provider))->run($pairs, 0.80, 0.99, 0.01))
        ->toThrow(RuntimeException::class, 'boom');
});
