<?php

namespace Database\Factories\Domains\Integrations;

use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\TenantIntegration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationCredential>
 */
class IntegrationCredentialFactory extends Factory
{
    protected $model = IntegrationCredential::class;

    public function definition(): array
    {
        return [
            'tenant_integration_id' => TenantIntegration::factory(),
            'key' => fake()->randomElement(['api_key', 'api_secret', 'access_token']),
            'value_encrypted' => fake()->sha256(),
            'expires_at' => null,
            'rotated_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function expiresInFuture(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->addMonth(),
        ]);
    }

    public function rotated(): static
    {
        return $this->state(fn () => [
            'rotated_at' => now(),
        ]);
    }
}
