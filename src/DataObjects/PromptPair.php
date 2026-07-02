<?php

namespace Yegoragapov\LlmCache\DataObjects;

/**
 * One labeled calibration pair: would serving A's cached answer for prompt B be
 * correct (`reuse = true`) or a false hit (`reuse = false`)? See
 * docs/specs/llm-cache-calibrate-spec.md §3.1.
 */
final class PromptPair
{
    public function __construct(
        public readonly string $a,
        public readonly string $b,
        public readonly bool $reuse,
        public readonly ?string $note = null,
        /** 1-based source line, for error reporting; null when built in-memory. */
        public readonly ?int $line = null,
    ) {
    }
}
