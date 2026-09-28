<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Assets\Models\Asset;
use App\Domains\Integrations\Jobs\CheckIntegrationHealthJob;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Notifications\Models\Notification;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Decisión 2026-09-28: el admin de la empresa se entera en cuanto la
 * integración falla o se calla, una vez sincronizadas las entidades
 * principales. Antes la flota podía dejar de vigilarse con la pantalla "en
 * calma".
 */
class CheckIntegrationHealthJobTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->team = User::factory()->withVerifiedPhone()->create()->currentTeam;
    }

    private function integration(array $attributes = [], ?Team $team = null): TenantIntegration
    {
        $team ??= $this->team;
        Asset::factory()->create(['team_id' => $team->id]);

        return TenantIntegration::factory()->active()->create(array_merge([
            'team_id' => $team->id,
            'last_sync_at' => now()->subDay(),
            'last_location_poll_at' => now()->subMinute(),
        ], $attributes));
    }

    private function alerts(?Team $team = null): int
    {
        return Notification::withoutGlobalScopes()
            ->where('team_id', ($team ?? $this->team)->id)
            ->where('notification_type', 'integration.health')
            ->count();
    }

    private function runHealthCheck(): void
    {
        app()->call([new CheckIntegrationHealthJob, 'handle']);
    }

    public function test_a_healthy_integration_raises_nothing(): void
    {
        $this->integration();

        $this->runHealthCheck();

        $this->assertSame(0, $this->alerts());
    }

    public function test_an_integration_in_error_warns_the_admin_once_per_episode(): void
    {
        $this->integration()->forceFill([
            'status' => 'error',
            'last_error_at' => now()->subMinutes(3),
            'last_error_message' => '401 Unauthorized',
        ])->save();

        $this->runHealthCheck();
        $this->runHealthCheck();

        $this->assertSame(1, $this->alerts());

        $notice = Notification::withoutGlobalScopes()->where('notification_type', 'integration.health')->sole();
        $this->assertStringContainsString('401', (string) $notice->body_preview);
        $this->assertContains('sms', $notice->payload_json['force_channels']);
    }

    public function test_a_silent_integration_warns_the_admin(): void
    {
        $this->integration(['last_location_poll_at' => now()->subHour()]);

        $this->runHealthCheck();

        $this->assertSame(1, $this->alerts());
    }

    public function test_nothing_is_watched_before_the_first_sync_of_the_main_entities(): void
    {
        $this->integration(['last_sync_at' => null, 'last_location_poll_at' => null]);

        $this->runHealthCheck();

        $this->assertSame(0, $this->alerts());
    }

    public function test_a_fleet_with_nothing_monitored_is_not_alerted(): void
    {
        $integration = $this->integration(['last_location_poll_at' => now()->subHour()]);
        Asset::withoutGlobalScopes()->where('team_id', $integration->team_id)->update(['monitoring_state' => 'pending']);

        $this->runHealthCheck();

        $this->assertSame(0, $this->alerts());
    }

    public function test_the_alert_stays_in_the_tenant_of_the_integration(): void
    {
        $other = User::factory()->create()->currentTeam;
        $this->integration(['last_location_poll_at' => now()->subHour()]);
        $this->integration([], $other);

        $this->assertNoTenantLeak($this->team, fn () => $this->runHealthCheck());

        $this->assertSame(1, $this->alerts());
        $this->assertSame(0, $this->alerts($other));
    }
}
