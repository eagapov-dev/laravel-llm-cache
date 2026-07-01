<?php

namespace Yegoragapov\LlmCache\Exceptions;

use RuntimeException;

/**
 * Thrown at boot when the configured provider's dimensions() does not match
 * the configured vector dimension (and therefore the migration's vector(N)).
 */
class DimensionMismatchException extends RuntimeException
{
    public static function make(string $provider, int $providerDimension, int $configuredDimension): self
    {
        return new self(sprintf(
            'Embedding provider [%s] produces %d-dimensional vectors, but llm-cache.dimension is %d. '
            .'Provider dimension and the migration vector(N) must match. '
            .'Fix config or re-run the migration + flush the cache.',
            $provider,
            $providerDimension,
            $configuredDimension,
        ));
    }
}
