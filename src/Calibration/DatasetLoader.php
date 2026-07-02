<?php

namespace Yegoragapov\LlmCache\Calibration;

use RuntimeException;
use Yegoragapov\LlmCache\DataObjects\PromptPair;

/**
 * Loads the JSONL calibration dataset (spec §3.1), one labeled pair per line.
 *
 * Streams the file line-by-line so a huge dataset never loads whole into memory.
 * Blank lines are ignored. A malformed/invalid line aborts under $strict (fail
 * fast, no partial run) or is skipped with a warning otherwise.
 */
final class DatasetLoader
{
    /**
     * @return array{pairs: list<PromptPair>, skipped: int, warnings: list<string>}
     */
    public function load(string $path, bool $strict = true): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("dataset not found: {$path}");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("dataset could not be opened: {$path}");
        }

        $pairs = [];
        $warnings = [];
        $skipped = 0;
        $line = 0;

        try {
            while (($raw = fgets($handle)) !== false) {
                $line++;
                $trimmed = trim($raw);

                if ($trimmed === '') {
                    continue; // blank lines are allowed and ignored
                }

                $error = null;
                $pair = $this->parseLine($trimmed, $line, $error);

                if ($pair === null) {
                    if ($strict) {
                        throw new RuntimeException($error ?? "line {$line}: malformed dataset line.");
                    }

                    $warnings[] = ($error ?? "line {$line}: malformed").' (skipped)';
                    $skipped++;

                    continue;
                }

                $pairs[] = $pair;
            }
        } finally {
            fclose($handle);
        }

        return ['pairs' => $pairs, 'skipped' => $skipped, 'warnings' => $warnings];
    }

    /**
     * Parse and validate one line. Returns null and sets $error on failure.
     */
    private function parseLine(string $json, int $line, ?string &$error): ?PromptPair
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            $error = "line {$line}: not valid JSON.";

            return null;
        }

        foreach (['a', 'b'] as $field) {
            $value = $decoded[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $error = "line {$line}: field \"{$field}\" is required and must be a non-empty string.";

                return null;
            }
        }

        if (! array_key_exists('reuse', $decoded) || ! is_bool($decoded['reuse'])) {
            $error = "line {$line}: field \"reuse\" is required and must be a boolean.";

            return null;
        }

        $note = $decoded['note'] ?? null;

        return new PromptPair(
            a: $decoded['a'],
            b: $decoded['b'],
            reuse: $decoded['reuse'],
            note: is_string($note) ? $note : null,
            line: $line,
        );
    }
}
