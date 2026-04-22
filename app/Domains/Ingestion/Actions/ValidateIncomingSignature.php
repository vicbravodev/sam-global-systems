<?php

namespace App\Domains\Ingestion\Actions;

class ValidateIncomingSignature
{
    /**
     * Validate an HMAC signature using timing-safe comparison.
     */
    public function execute(
        string $payload,
        string $signature,
        string $secret,
        string $algorithm = 'sha256',
    ): bool {
        $computed = hash_hmac($algorithm, $payload, $secret);

        return hash_equals($computed, $signature);
    }
}
