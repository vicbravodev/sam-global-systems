<?php

namespace App\Domains\AI\Support;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

/**
 * Classifies a provider failure as transient (worth a queue retry) or final.
 *
 * Transient: rate limit (429), provider overloaded / 5xx, request timeouts
 * (408) and connection errors. Anything else (bad request, auth, invalid
 * output) will fail the same way on retry. Walks the `previous` chain because
 * the SDK wrappers rethrow provider errors wrapped in a RuntimeException.
 */
final class RetryableAIError
{
    public static function isRetryable(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RateLimitedException
                || $current instanceof ProviderOverloadedException
                || $current instanceof ConnectionException
                || $current instanceof ConnectException
            ) {
                return true;
            }

            if ($current instanceof RequestException && $current->response !== null) {
                $status = $current->response->status();

                if ($status === 408 || $status === 429 || $status >= 500) {
                    return true;
                }
            }

            $message = strtolower($current->getMessage());

            if (str_contains($message, 'timed out')
                || str_contains($message, 'curl error 28')
                || str_contains($message, 'connection refused')
                || str_contains($message, 'connection reset')
            ) {
                return true;
            }
        }

        return false;
    }
}
