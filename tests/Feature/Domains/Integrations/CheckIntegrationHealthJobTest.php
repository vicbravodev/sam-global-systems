<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Assets\Models\Asset;
use App\Domains\Integrations\Jobs\CheckIntegrationHealthJob;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\Notifications\Models\Notification;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
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
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function webhook(TenantIntegration $integration, array $attributes = []): WebhookEndpoint
    {
        return WebhookEndpoint::factory()->create(array_merge([
            'tenant_integration_id' => $integration->id,
            'secret_configured_at' => now()->subDays(2),
        ], $attributes));
    }

    private function webhookAlerts(): int
    {
        return Notification::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('notification_type', 'integration.health')
            ->where('event_key', 'like', 'integration_webhook_%')
            ->count();
    }

    public function test_rejected_webhooks_degrade_the_integration_even_with_a_live_position_feed(): void
    {
        // El feed de posiciones está vivo: no debe enmascarar que Samsara
        // manda pánicos que SAM rechaza.
        $integration = $this->integration(['last_location_poll_at' => now()->subMinute()]);
        $this->webhook($integration, [
            'last_valid_received_at' => now()->subHours(3),
            'last_rejected_at' => now()->subMinutes(2),
            'last_rejection_reason' => 'invalid_signature',
        ]);

        $this->runHealthCheck();
        $this->runHealthCheck();

        $this->assertSame(1, $this->webhookAlerts(), 'Un aviso por episodio de rechazos.');
        $notice = Notification::withoutGlobalScopes()->where('notification_type', 'integration.health')->sole();
        $this->assertStringContainsString('webhook', mb_strtolower((string) $notice->subject));
        $this->assertSystemLogged('integrations.health.webhook_degraded', fn (array $c) => $c['reason'] === 'rejecting'
            && $c['input']['integration_id'] === $integration->id
            && $c['input']['last_rejection_reason'] === 'invalid_signature');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_webhook_without_secret_degrades_the_integration(): void
    {
        $integration = $this->integration();
        $this->webhook($integration, ['secret' => null, 'secret_configured_at' => null]);

        $this->runHealthCheck();

        $this->assertSame(1, $this->webhookAlerts());
        $this->assertSystemLogged('integrations.health.webhook_degraded', fn (array $c) => $c['reason'] === 'pending_secret');
    }

    public function test_old_rejections_followed_by_valid_deliveries_are_healthy(): void
    {
        $integration = $this->integration();
        $this->webhook($integration, [
            'last_valid_received_at' => now()->subMinutes(5),
            'last_rejected_at' => now()->subHour(),
            'last_rejection_reason' => 'invalid_signature',
        ]);

        $this->runHealthCheck();

        $this->assertSame(0, $this->alerts());
    }

    public function test_a_configured_webhook_that_only_received_rejections_long_ago_is_not_flagged(): void
    {
        $integration = $this->integration();
        $this->webhook($integration, [
            'last_rejected_at' => now()->subDays(3),
            'last_rejection_reason' => 'invalid_signature',
        ]);

        $this->runHealthCheck();

        $this->assertSame(0, $this->alerts());
    }

    public function test_rejected_webhooks_do_not_count_as_data_for_the_silence_check(): void
    {
        $integration = $this->integration(['last_location_poll_at' => now()->subHour()]);
        $this->webhook($integration, [
            'last_received_at' => now()->subMinute(),
            'last_valid_received_at' => now()->subHours(2),
        ]);

        $this->runHealthCheck();

        $this->assertSame(1, Notification::withoutGlobalScopes()
            ->where('notification_type', 'integration.health')
            ->where('event_key', 'like', 'integration_silent:%')
            ->count());
    }
}
