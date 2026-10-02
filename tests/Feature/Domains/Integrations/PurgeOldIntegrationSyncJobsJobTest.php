<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Jobs\PurgeOldIntegrationSyncJobsJob;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class PurgeOldIntegrationSyncJobsJobTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    public function test_it_removes_finished_runs_past_retention_and_keeps_the_rest(): void
    {
        $this->freezeTime();
        config(['pipeline.retention.integration_sync_jobs_days' => 30]);

        $integration = TenantIntegration::factory()->create();
        $sync = IntegrationSyncJob::factory()->for($integration, 'tenantIntegration');

        $this->travelTo(now()->subDays(31));
        $sync->completed()->create();
        $sync->failed()->create();
        // En vuelo: nunca se purgan, aunque sean viejas.
        $pending = $sync->create();
        $running = $sync->running()->create();
        $this->travelBack();

        $recent = $sync->completed()->create(['created_at' => now()->subDays(29)]);

        $deleted = (new PurgeOldIntegrationSyncJobsJob)->handle();

        $this->assertSame(2, $deleted);
        $this->assertEqualsCanonicalizing(
            [$pending->id, $running->id, $recent->id],
            IntegrationSyncJob::query()->pluck('id')->all(),
        );

        $context = $this->assertSystemLogged('integrations.purge.completed');
        $this->assertSame('ok', $context['outcome']);
        $this->assertSame('integration_sync_jobs', $context['input']['table']);
        $this->assertSame(30, $context['calc']['retention_days']);
        $this->assertSame('config', $context['calc']['retention_source']);
        $this->assertSame(now()->subDays(30)->toIso8601String(), $context['calc']['cutoff']);
        $this->assertSame(['completed', 'failed'], $context['calc']['statuses']);
        $this->assertSame(2, $context['result']['removed_count']);
        $this->assertSame(1, $context['result']['batches_count']);
        $this->assertStringNotContainsString('team_id', (string) json_encode($context));
        $this->assertStringNotContainsString('integration_id', (string) json_encode($context));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_it_sweeps_every_tenant_and_leaves_other_tables_alone(): void
    {
        $this->freezeTime();

        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $integrationA = TenantIntegration::factory()->create(['team_id' => $teamA->id]);
        $integrationB = TenantIntegration::factory()->create(['team_id' => $teamB->id]);

        $this->travelTo(now()->subDays(60));
        IntegrationSyncJob::factory()->for($integrationA, 'tenantIntegration')->completed()->create();
        IntegrationSyncJob::factory()->for($integrationB, 'tenantIntegration')->completed()->create();
        $webhook = WebhookEvent::factory()->create(['team_id' => $teamB->id, 'received_at' => now()]);
        $usage = UsageEvent::factory()->create(['team_id' => $teamB->id]);
        $this->travelBack();

        $deleted = TenantContext::for($teamA->id, fn (): int => (new PurgeOldIntegrationSyncJobsJob(retentionDays: 30))->handle());

        $this->assertSame(2, $deleted);
        $this->assertSame(0, IntegrationSyncJob::query()->count());

        TenantContext::withoutTenant(function () use ($integrationA, $integrationB, $webhook, $usage): void {
            $this->assertSame(2, TenantIntegration::query()->whereKey([$integrationA->id, $integrationB->id])->count());
            $this->assertTrue(WebhookEvent::query()->whereKey($webhook->id)->exists());
            $this->assertTrue(UsageEvent::query()->whereKey($usage->id)->exists());
        });
    }

    public function test_a_retention_below_one_day_disables_the_purge(): void
    {
        IntegrationSyncJob::factory()->completed()->create(['created_at' => now()->subYear()]);

        $this->assertSame(0, (new PurgeOldIntegrationSyncJobsJob(retentionDays: 0))->handle());
        $this->assertSame(1, IntegrationSyncJob::query()->count());

        $context = $this->assertSystemLogged('integrations.purge.completed');
        $this->assertSame('skipped', $context['outcome']);
        $this->assertSame('disabled', $context['reason']);
        $this->assertSame('argument', $context['calc']['retention_source']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_it_is_scheduled_daily_on_one_server(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => $event instanceof CallbackEvent
                && str_contains((string) $event->description, PurgeOldIntegrationSyncJobsJob::class));

        $this->assertNotNull($event, 'PurgeOldIntegrationSyncJobsJob must be scheduled');
        $this->assertSame('15 4 * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
    }
}
