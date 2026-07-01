<?php

namespace Yegoragapov\LlmCache\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Throwable;

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
            ->timeout($timeout)
            // Do NOT follow redirects: the HTTPS guard only validates the initial
            // URL, so a 3xx to http://internal would otherwise downgrade the call.
            ->withOptions(['allow_redirects' => false]);

        if ($retries > 0) {
            // Small linear backoff; retry only transient failures (timeouts, 5xx,
            // 429) so a permanent 4xx (bad key/input) doesn't burn an extra call.
            // throw=false so a final failure is handled by the caller's ->failed().
            $request = $request->retry(
                $retries + 1,
                100,
                static fn (Throwable $e): bool => self::isTransientHttpError($e),
                throw: false,
            );
        }

        return $request;
    }

    protected static function isTransientHttpError(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof RequestException && $e->response !== null) {
            $status = $e->response->status();

            return $status >= 500 || $status === 429;
        }

        return false;
    }
}
