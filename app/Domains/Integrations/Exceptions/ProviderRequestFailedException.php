<?php

namespace App\Domains\Integrations\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A provider API call answered with a non-2xx status.
 *
 * Thrown instead of silently returning an empty result so callers that keep
 * state (feed cursors, `last_polled_at`, sync bookkeeping) never advance it
 * over a request that did not actually succeed.
 */
class ProviderRequestFailedException extends RuntimeException
{
    final public function __construct(
        public readonly string $endpoint,
        public readonly int $status,
        public readonly ?string $providerMessage = null,
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct(sprintf(
            'Samsara %s respondió HTTP %d%s%s',
            $endpoint,
            $status,
            $providerMessage !== null && $providerMessage !== '' ? ': '.$providerMessage : '',
            $requestId !== null && $requestId !== '' ? " (requestId {$requestId})" : '',
        ));
    }

    public static function fromResponse(string $endpoint, Response $response): static
    {
        $message = $response->json('message');
        $requestId = $response->json('requestId');
        $retryAfter = $response->header('Retry-After');

        return new static(
            endpoint: $endpoint,
            status: $response->status(),
            providerMessage: is_string($message) ? mb_substr($message, 0, 300) : null,
            requestId: is_string($requestId) ? $requestId : null,
            retryAfterSeconds: is_numeric($retryAfter) ? max(0, (int) $retryAfter) : null,
        );
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }

    /** Token inválido, revocado o sin el permiso (scope) que pide la llamada. */
    public function isUnauthorized(): bool
    {
        return in_array($this->status, [401, 403], true);
    }
}
