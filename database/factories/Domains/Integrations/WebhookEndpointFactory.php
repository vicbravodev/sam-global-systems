<?php

namespace Database\Factories\Domains\Integrations;

use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    public function definition(): array
    {
        return [
            'tenant_integration_id' => TenantIntegration::factory(),
            'url' => Str::uuid()->toString(),
            'secret' => Str::random(64),
            'secret_configured_at' => now(),
            'status' => 'active',
        ];
    }

    /**
     * Recién conectado: la Secret Key de Samsara aún no se copió a SAM.
     */
    public function withoutSecret(): static
    {
        return $this->state(fn () => [
            'secret' => null,
            'secret_configured_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => 'inactive',
        ]);
    }
}
