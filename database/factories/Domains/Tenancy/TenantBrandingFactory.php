<?php

namespace Database\Factories\Domains\Tenancy;

use App\Domains\Tenancy\Models\TenantBranding;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantBranding>
 */
class TenantBrandingFactory extends Factory
{
    protected $model = TenantBranding::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'logo_url' => null,
            'primary_color' => null,
            'secondary_color' => null,
            'display_name' => null,
            'email_signature' => null,
            'custom_domain' => null,
        ];
    }

    public function withBranding(): static
    {
        return $this->state(fn () => [
            'logo_url' => fake()->imageUrl(),
            'primary_color' => fake()->hexColor(),
            'secondary_color' => fake()->hexColor(),
            'display_name' => fake()->company(),
        ]);
    }
}
