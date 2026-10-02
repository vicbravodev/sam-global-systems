<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Domains\Ingestion\Jobs\PurgeOldFailedJobsJob;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Support\TenantContext;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class PurgeOldFailedJobsJobTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    public function test_it_removes_failed_jobs_past_retention_and_keeps_the_rest(): void
    {
        $this->freezeTime();
        config(['pipeline.retention.failed_jobs_days' => 30]);

        $this->failedJob(now()->subDays(31));
        $this->failedJob(now()->subDays(200));
        $kept = $this->failedJob(now()->subDays(29));

        $this->travelTo(now()->subDays(120));
        $usage = UsageEvent::factory()->create();
        $this->travelBack();

        $deleted = app()->call([new PurgeOldFailedJobsJob, 'handle']);

        $this->assertSame(2, $deleted);
        $this->assertSame([$kept], DB::table('failed_jobs')->pluck('uuid')->all());
        $this->assertTrue(TenantContext::withoutTenant(fn () => UsageEvent::query()->whereKey($usage->id)->exists()));

        $context = $this->assertSystemLogged('queue.purge.completed');
        $this->assertSame('ok', $context['outcome']);
        $this->assertSame('failed_jobs', $context['input']['table']);
        $this->assertSame(30, $context['calc']['retention_days']);
        $this->assertSame('config', $context['calc']['retention_source']);
        $this->assertSame(now()->subDays(30)->toIso8601String(), $context['calc']['cutoff']);
        $this->assertSame(2, $context['result']['removed_count']);
        // Nunca el payload ni la excepción de los jobs borrados.
        $this->assertStringNotContainsString('SecretPayload', (string) json_encode($context));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_retention_below_one_day_disables_the_purge(): void
    {
        $this->failedJob(now()->subYear());

        $this->assertSame(0, app()->call([new PurgeOldFailedJobsJob(retentionDays: 0), 'handle']));
        $this->assertSame(1, DB::table('failed_jobs')->count());

        $context = $this->assertSystemLogged('queue.purge.completed');
        $this->assertSame('skipped', $context['outcome']);
        $this->assertSame('disabled', $context['reason']);
        $this->assertSame('argument', $context['calc']['retention_source']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_provider_that_cannot_prune_is_skipped(): void
    {
        $this->assertSame(0, (new PurgeOldFailedJobsJob)->handle(new NullFailedJobProvider));

        $context = $this->assertSystemLogged('queue.purge.completed');
        $this->assertSame('skipped', $context['outcome']);
        $this->assertSame('provider_not_prunable', $context['reason']);
        $this->assertSame('NullFailedJobProvider', $context['calc']['provider']);
    }

    public function test_it_is_scheduled_daily_on_one_server(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => $event instanceof CallbackEvent
                && str_contains((string) $event->description, PurgeOldFailedJobsJob::class));

        $this->assertNotNull($event, 'PurgeOldFailedJobsJob must be scheduled');
        $this->assertSame('25 4 * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
    }

    private function failedJob(\DateTimeInterface $failedAt): string
    {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'SecretPayload']),
            'exception' => 'RuntimeException: boom',
            'failed_at' => $failedAt,
        ]);

        return $uuid;
    }
}
