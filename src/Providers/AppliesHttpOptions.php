<?php

namespace Yegoragapov\LlmCache\Providers;

use Illuminate\Http\Client\PendingRequest;

/**
 * Bounds every outbound embedding call with a connect timeout, a response
 * timeout, and a small retry budget. Without these, a slow-but-not-failing
 * endpoint hangs the request thread — and fail-open only catches *thrown*
 * failures, never a hang. Values come from the provider's own config block
 * (see config/llm-cache.php `providers.*`).
 *
 * @property array<string, mixed> $config
 */
trait AppliesHttpOptions
{
    protected function applyHttpOptions(PendingRequest $request): PendingRequest
    {
        $connectTimeout = (int) ($this->config['connect_timeout'] ?? 3);
        $timeout = (int) ($this->config['timeout'] ?? 10);
        $retries = max(0, (int) ($this->config['retries'] ?? 1));

        $request = $request
            ->connectTimeout($connectTimeout)
            ->timeout($timeout);

        if ($retries > 0) {
            // Small linear backoff; throw=false so a final failure is handled by
            // the caller's ->failed() check rather than raising here.
            $request = $request->retry($retries + 1, 100, throw: false);
        }

        return $request;
    }
}
