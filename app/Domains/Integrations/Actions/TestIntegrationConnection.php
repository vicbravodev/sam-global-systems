<?php

namespace App\Domains\Integrations\Actions;

use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Events\IntegrationStatusChanged;
use App\Domains\Integrations\Models\TenantIntegration;

class TestIntegrationConnection
{
    public function __construct(
        private ProviderAdapter $providerAdapter,
    ) {}

    /**
     * @return array{success: bool, message: string}
     */
    public function execute(TenantIntegration $integration): array
    {
        $result = $this->providerAdapter->testConnection($integration);

        if ($result['success']) {
            $integration->update([
                'status' => TenantIntegrationStatus::Active,
                'last_error_at' => null,
                'last_error_message' => null,
            ]);
        } else {
            $integration->update([
                'status' => TenantIntegrationStatus::Error,
                'last_error_at' => now(),
                'last_error_message' => $result['message'],
            ]);
        }

        IntegrationStatusChanged::dispatch(
            $integration->team_id,
            $integration->id,
            $integration->provider->code,
            $integration->status->value,
        );

        return $result;
    }
}
