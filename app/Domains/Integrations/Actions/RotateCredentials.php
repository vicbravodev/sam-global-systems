<?php

namespace App\Domains\Integrations\Actions;

use App\Domains\Integrations\Models\IntegrationCredential;

class RotateCredentials
{
    /**
     * Rotate a credential value without downtime.
     * The new value is written before the old one is considered invalidated.
     */
    public function execute(IntegrationCredential $credential, string $newValue): void
    {
        $credential->update([
            'value_encrypted' => $newValue,
            'rotated_at' => now(),
        ]);
    }
}
