<?php

namespace App\Domains\Integrations\Exceptions;

/**
 * HTTP 429. `$retryAfterSeconds` is the provider's own hint (Samsara sends
 * `Retry-After` in seconds, with decimals).
 */
class ProviderRateLimited extends ProviderRequestFailed
{
    public function __construct(public readonly float $retryAfterSeconds)
    {
        parent::__construct("Provider rate limit hit; retry after {$retryAfterSeconds}s.");
    }
}
