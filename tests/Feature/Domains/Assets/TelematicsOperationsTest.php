<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Enums\TelematicsFeed;
use App\Domains\Assets\Models\TelematicsFeedCursor;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Events\IntegrationStatusChanged;
use App\Domains\Integrations\Models\TenantIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class TelematicsOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reactivating_an_integration_clears_its_feed_backoff_only(): void
    {
        $fixed = TelematicsFeedCursor::factory()->create(['consecutive_failures' => 4, 'paused_until' => now()->addMinutes(5), 'last_error' => 'x']);
        $other = TelematicsFeedCursor::factory()->create(['consecutive_failures' => 2, 'paused_until' => now()->addMinutes(5)]);

        IntegrationStatusChanged::dispatch($fixed->team_id, $fixed->tenant_integration_id, 'samsara', TenantIntegrationStatus::Active->value);

        $fixed = TelematicsFeedCursor::withoutGlobalScopes()->find($fixed->id);
        $this->assertSame(0, $fixed->consecutive_failures);
        $this->assertNull($fixed->paused_until);
        $this->assertNull($fixed->last_error);

        $this->assertSame(2, TelematicsFeedCursor::withoutGlobalScopes()->find($other->id)->consecutive_failures);
    }

    public function test_an_error_status_does_not_clear_anything(): void
    {
        $cursor = TelematicsFeedCursor::factory()->create(['consecutive_failures' => 4]);

        IntegrationStatusChanged::dispatch($cursor->team_id, $cursor->tenant_integration_id, 'samsara', TenantIntegrationStatus::Error->value);

        $this->assertSame(4, TelematicsFeedCursor::withoutGlobalScopes()->find($cursor->id)->consecutive_failures);
    }

    public function test_the_status_command_shows_lag_pause_and_errors_per_feed(): void
    {
        $integration = TenantIntegration::factory()->active()->create(['name' => 'Samsara Norte']);

        TelematicsFeedCursor::factory()->create([
            'tenant_integration_id' => $integration->id,
            'feed' => TelematicsFeed::Motion,
            'last_data_at' => now()->subSeconds(7),
            'last_polled_at' => now()->subSeconds(2),
            'paused_until' => now()->addSeconds(30),
            'consecutive_failures' => 1,
            'last_error' => 'Provider rate limit hit; retry after 30s.',
        ]);

        $this->assertSame(0, Artisan::call('telematics:status'));

        // One row per feed: which tenant, how far behind, why it is paused.
        $output = Artisan::output();
        $this->assertStringContainsString('Samsara Norte', $output);
        $this->assertStringContainsString('7 s', $output);
        $this->assertStringContainsString('Provider rate limit hit', $output);
    }
}
