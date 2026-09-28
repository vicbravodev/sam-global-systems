<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Listeners\NotifyPendingAssetsOnSyncCompleted;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Notifications\AssetsPendingMonitoringNotification;
use App\Domains\Integrations\Events\IntegrationSyncCompleted;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Decisión 2026-09-28: en cuanto se sincronizan las entidades principales, el
 * admin se entera de las unidades nuevas que quedaron sin vigilar.
 */
class NotifyPendingAssetsOnSyncCompletedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_hears_about_new_pending_units_after_a_sync(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $integration = TenantIntegration::factory()->active()->create(['team_id' => $team->id]);
        $sync = IntegrationSyncJob::factory()->create([
            'tenant_integration_id' => $integration->id,
            'started_at' => now()->subMinute(),
        ]);

        // Una unidad vieja ya pendiente no cuenta como nueva.
        Asset::factory()->pendingMonitoring()->create(['team_id' => $team->id, 'created_at' => now()->subDays(3)]);
        Asset::factory()->pendingMonitoring()->count(2)->create(['team_id' => $team->id]);

        app(NotifyPendingAssetsOnSyncCompleted::class)->handle(
            new IntegrationSyncCompleted($team->id, $integration->id, $sync->id, 3),
        );

        Notification::assertSentTo($owner, AssetsPendingMonitoringNotification::class,
            fn (AssetsPendingMonitoringNotification $notification) => $notification->newlyPending === 2);
    }

    public function test_a_sync_job_of_another_tenant_is_ignored(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $foreign = TenantIntegration::factory()->active()->create(['team_id' => Team::factory()->create()->id]);
        $foreignSync = IntegrationSyncJob::factory()->create([
            'tenant_integration_id' => $foreign->id,
            'started_at' => now()->subMinute(),
        ]);
        Asset::factory()->pendingMonitoring()->create(['team_id' => $team->id]);

        app(NotifyPendingAssetsOnSyncCompleted::class)->handle(
            new IntegrationSyncCompleted($team->id, 999999, $foreignSync->id, 1),
        );

        Notification::assertNothingSent();
    }
}
