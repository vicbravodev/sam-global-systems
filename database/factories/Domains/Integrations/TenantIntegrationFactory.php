<?php

namespace Database\Factories\Domains\Integrations;

use App\Domains\Integrations\Enums\AuthType;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantIntegration>
 */
class TenantIntegrationFactory extends Factory
{
    protected $model = TenantIntegration::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'provider_id' => IntegrationProvider::factory(),
            'name' => fake()->words(2, true),
            'status' => TenantIntegrationStatus::Pending,
            'auth_type' => AuthType::ApiKey,
            'credentials_encrypted' => fake()->sha256(),
            'config_json' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => TenantIntegrationStatus::Active,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => TenantIntegrationStatus::Inactive,
        ]);
    }

    public function error(): static
    {
        return $this->state(fn () => [
            'status' => TenantIntegrationStatus::Error,
            'last_error_at' => now(),
            'last_error_message' => 'Connection refused',
        ]);
    }

    public function withOauth(): static
    {
        return $this->state(fn () => [
            'auth_type' => AuthType::Oauth2,
        ]);
    }
}
