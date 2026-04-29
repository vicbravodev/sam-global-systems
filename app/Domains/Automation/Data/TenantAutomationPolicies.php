<?php

namespace App\Domains\Automation\Data;

final class TenantAutomationPolicies
{
    /**
     * @param  array<int, int>  $retryBackoffSeconds  Seconds to wait between retries (per attempt index).
     */
    public function __construct(
        public readonly string $automationLevel,
        public readonly int $maxRetries,
        public readonly array $retryBackoffSeconds,
        public readonly int $confirmationTtlSeconds,
    ) {}

    public static function defaults(): self
    {
        return new self(
            automationLevel: 'semi',
            maxRetries: 3,
            retryBackoffSeconds: [10, 60, 300],
            confirmationTtlSeconds: 30 * 60,
        );
    }
}
