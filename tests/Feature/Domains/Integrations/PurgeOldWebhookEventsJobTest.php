<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Jobs\PurgeOldWebhookEventsJob;
use App\Domains\Integrations\Models\WebhookEvent;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class PurgeOldWebhookEventsJobTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    public function test_it_removes_resolved_webhooks_past_retention_and_keeps_the_rest(): void
    {
        $this->freezeTime();
        config(['pipeline.retention.webhook_events_days' => 30]);

        $team = Team::factory()->create();
        $old = ['team_id' => $team->id, 'received_at' => now()->subDays(31)];

        WebhookEvent::factory()->processed()->create($old);
        WebhookEvent::factory()->failed()->create($old);
        WebhookEvent::factory()->invalidSignature()->create(['team_id' => $team->id, 'received_at' => now()->subDays(90)]);

        // Atascados: nunca se purgan, aunque sean viejos.
        $stuck = WebhookEvent::factory()->create($old);
        // Dentro de la ventana.
        $recent = WebhookEvent::factory()->processed()->create(['team_id' => $team->id, 'received_at' => now()->subDays(29)]);

        $deleted = (new PurgeOldWebhookEventsJob)->handle();

        $this->assertSame(3, $deleted);
        $this->assertEqualsCanonicalizing(
            [$stuck->id, $recent->id],
            TenantContext::withoutTenant(fn () => WebhookEvent::query()->pluck('id')->all()),
        );

        $context = $this->assertSystemLogged('integrations.purge.completed');
        $this->assertSame('ok', $context['outcome']);
        $this->assertSame('webhook_events', $context['input']['table']);
        $this->assertSame(30, $context['calc']['retention_days']);
        $this->assertSame('config', $context['calc']['retention_source']);
        $this->assertSame(now()->subDays(30)->toIso8601String(), $context['calc']['cutoff']);
        $this->assertSame(1000, $context['calc']['chunk_size']);
        $this->assertSame(['processed', 'failed', 'invalid_signature'], $context['calc']['statuses']);
        $this->assertSame(3, $context['result']['removed_count']);
        $this->assertSame(1, $context['result']['batches_count']);
        // Recorrido de plataforma: sólo conteos, nunca un tenant ni un id.
        $this->assertStringNotContainsString('team_id', (string) json_encode($context));
        $this->assertStringNotContainsString('webhook_event_id', (string) json_encode($context));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_it_sweeps_every_tenant_even_when_dispatched_inside_one(): void
    {
        $this->freezeTime();

        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        WebhookEvent::factory()->processed()->create(['team_id' => $teamA->id, 'received_at' => now()->subDays(40)]);
        WebhookEvent::factory()->processed()->create(['team_id' => $teamB->id, 'received_at' => now()->subDays(40)]);
        $keptB = WebhookEvent::factory()->processed()->create(['team_id' => $teamB->id, 'received_at' => now()->subDay()]);

        $deleted = TenantContext::for($teamA->id, function () use ($teamA): int {
            $deleted = (new PurgeOldWebhookEventsJob(retentionDays: 30))->handle();

            // El contexto del llamador queda como estaba.
            $this->assertSame($teamA->id, TenantContext::id());

            return $deleted;
        });

        $this->assertSame(2, $deleted);
        $this->assertSame(
            [$keptB->id],
            TenantContext::withoutTenant(fn () => WebhookEvent::query()->pluck('id')->all()),
        );
        $this->assertSame('argument', $this->assertSystemLogged('integrations.purge.completed')['calc']['retention_source']);
    }

    public function test_it_never_touches_pipeline_or_billable_rows(): void
    {
        $this->freezeTime();
        $team = Team::factory()->create();

        $this->travelTo(now()->subDays(120));
        $rawEvent = RawEvent::factory()->create(['team_id' => $team->id]);
        $usage = UsageEvent::factory()->create(['team_id' => $team->id]);
        $charge = MessagingCharge::factory()->create(['team_id' => $team->id]);
        $this->travelBack();

        WebhookEvent::factory()->processed()->create(['team_id' => $team->id, 'received_at' => now()->subDays(120)]);

        $this->assertSame(1, (new PurgeOldWebhookEventsJob)->handle());

        TenantContext::withoutTenant(function () use ($rawEvent, $usage, $charge): void {
            $this->assertTrue(RawEvent::query()->whereKey($rawEvent->id)->exists());
            $this->assertTrue(UsageEvent::query()->whereKey($usage->id)->exists());
            $this->assertTrue(MessagingCharge::query()->whereKey($charge->id)->exists());
        });
    }

    public function test_a_retention_below_one_day_disables_the_purge(): void
    {
        $this->freezeTime();
        config(['pipeline.retention.webhook_events_days' => 0]);

        $team = Team::factory()->create();
        WebhookEvent::factory()->processed()->create(['team_id' => $team->id, 'received_at' => now()->subYear()]);

        $this->assertSame(0, (new PurgeOldWebhookEventsJob)->handle());
        $this->assertSame(1, TenantContext::withoutTenant(fn () => WebhookEvent::query()->count()));

        $context = $this->assertSystemLogged('integrations.purge.completed');
        $this->assertSame('skipped', $context['outcome']);
        $this->assertSame('disabled', $context['reason']);
        $this->assertSame(0, $context['calc']['retention_days']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_it_is_scheduled_daily_on_one_server(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => $event instanceof CallbackEvent
                && str_contains((string) $event->description, PurgeOldWebhookEventsJob::class));

        $this->assertNotNull($event, 'PurgeOldWebhookEventsJob must be scheduled');
        $this->assertSame('10 4 * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
    }
}
