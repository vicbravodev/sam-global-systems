<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Jobs\PurgeExpiredReplyTokensJob;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Domains\Notifications\Models\NotificationReplyToken;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class PurgeExpiredReplyTokensJobTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    public function test_it_removes_tokens_expired_beyond_retention_and_keeps_the_rest(): void
    {
        $this->freezeTime();
        config(['pipeline.retention.reply_tokens_days' => 30]);

        $team = Team::factory()->create();
        $incident = Incident::factory()->create(['team_id' => $team->id]);
        $token = NotificationReplyToken::factory()->for($incident)->state(['team_id' => $team->id]);

        $token->create(['expires_at' => now()->subDays(31)]);
        $token->consumed()->create(['expires_at' => now()->subDays(90)]);
        // Vencido, pero aún dentro de la ventana.
        $recentlyExpired = $token->create(['expires_at' => now()->subDays(29)]);
        // Vigente.
        $live = $token->create();

        $deleted = (new PurgeExpiredReplyTokensJob)->handle();

        $this->assertSame(2, $deleted);
        $this->assertEqualsCanonicalizing(
            [$recentlyExpired->id, $live->id],
            TenantContext::withoutTenant(fn () => NotificationReplyToken::query()->pluck('id')->all()),
        );
        // El incidente al que apuntaban sigue intacto.
        $this->assertTrue(TenantContext::withoutTenant(fn () => Incident::query()->whereKey($incident->id)->exists()));

        $context = $this->assertSystemLogged('notifications.purge.completed');
        $this->assertSame('ok', $context['outcome']);
        $this->assertSame('notification_reply_tokens', $context['input']['table']);
        $this->assertSame(30, $context['calc']['retention_days']);
        $this->assertSame('config', $context['calc']['retention_source']);
        $this->assertSame(now()->subDays(30)->toIso8601String(), $context['calc']['cutoff']);
        $this->assertSame(1000, $context['calc']['chunk_size']);
        $this->assertSame(2, $context['result']['removed_count']);
        $this->assertSame(1, $context['result']['batches_count']);
        // Nunca el teléfono ni el token.
        $this->assertStringNotContainsString('+52', (string) json_encode($context));
        $this->assertStringNotContainsString('team_id', (string) json_encode($context));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_it_sweeps_every_tenant_and_never_touches_billable_rows(): void
    {
        $this->freezeTime();

        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        foreach ([$teamA, $teamB] as $team) {
            NotificationReplyToken::factory()
                ->for(Incident::factory()->state(['team_id' => $team->id]))
                ->create(['team_id' => $team->id, 'expires_at' => now()->subDays(45)]);
        }

        $this->travelTo(now()->subDays(120));
        $usage = UsageEvent::factory()->create(['team_id' => $teamB->id]);
        $charge = MessagingCharge::factory()->create(['team_id' => $teamB->id]);
        $this->travelBack();

        $deleted = TenantContext::for($teamA->id, fn (): int => (new PurgeExpiredReplyTokensJob(retentionDays: 30))->handle());

        $this->assertSame(2, $deleted);

        TenantContext::withoutTenant(function () use ($usage, $charge): void {
            $this->assertSame(0, NotificationReplyToken::query()->count());
            $this->assertTrue(UsageEvent::query()->whereKey($usage->id)->exists());
            $this->assertTrue(MessagingCharge::query()->whereKey($charge->id)->exists());
        });
    }

    public function test_a_retention_below_one_day_disables_the_purge(): void
    {
        config(['pipeline.retention.reply_tokens_days' => -1]);

        $team = Team::factory()->create();
        NotificationReplyToken::factory()
            ->for(Incident::factory()->state(['team_id' => $team->id]))
            ->create(['team_id' => $team->id, 'expires_at' => now()->subYear()]);

        $this->assertSame(0, (new PurgeExpiredReplyTokensJob)->handle());
        $this->assertSame(1, TenantContext::withoutTenant(fn () => NotificationReplyToken::query()->count()));

        $context = $this->assertSystemLogged('notifications.purge.completed');
        $this->assertSame('skipped', $context['outcome']);
        $this->assertSame('disabled', $context['reason']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_it_is_scheduled_daily_on_one_server(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => $event instanceof CallbackEvent
                && str_contains((string) $event->description, PurgeExpiredReplyTokensJob::class));

        $this->assertNotNull($event, 'PurgeExpiredReplyTokensJob must be scheduled');
        $this->assertSame('20 4 * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
    }
}
