<?php

namespace Yegoragapov\LlmCache\Tests\Support;

use RuntimeException;
use Yegoragapov\LlmCache\Contracts\EmbeddingProvider;

/**
 * Test double that maps a known prompt to a caller-chosen vector, so the
 * acceptance suite can assert precise cosine relationships (hit vs miss)
 * deterministically — something the shipped hashed `null` provider can't offer.
 */
final class FakeEmbeddingProvider implements EmbeddingProvider
{
    /** @var array<string, array<int, float>> */
    private array $map = [];

    public function __construct(
        private int $dimensions = 16,
    ) {
    }

    /**
     * @param array<int, float> $vector
     */
    public function set(string $text, array $vector): self
    {
        $this->map[$text] = $vector;

        return $this;
    }

    public function embed(string $text): array
    {
        if (! array_key_exists($text, $this->map)) {
            throw new RuntimeException("FakeEmbeddingProvider has no vector primed for [{$text}].");
        }

        return $this->map[$text];
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function name(): string
    {
        return 'fake';
    }
}
