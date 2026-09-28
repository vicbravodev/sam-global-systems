<?php

namespace Database\Factories\Domains\Assets;

use App\Domains\Assets\Enums\TelematicsFeed;
use App\Domains\Assets\Models\TelematicsFeedCursor;
use App\Domains\Integrations\Models\TenantIntegration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelematicsFeedCursor>
 */
class TelematicsFeedCursorFactory extends Factory
{
    protected $model = TelematicsFeedCursor::class;

    public function definition(): array
    {
        return [
            'tenant_integration_id' => TenantIntegration::factory(),
            // Same tenant as its integration, never a team of its own (§2.1.9).
            'team_id' => fn (array $attributes) => TenantIntegration::withoutGlobalScopes()
                ->findOrFail($attributes['tenant_integration_id'])->team_id,
            'feed' => TelematicsFeed::Motion,
            'end_cursor' => null,
            'consecutive_failures' => 0,
        ];
    }
}
