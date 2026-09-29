<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Context\Actions\BuildEventContext;
use App\Domains\Context\Actions\BuildOperationalContextProfile;
use App\Domains\Context\Jobs\EnrichContextJob;
use App\Domains\Context\Listeners\EnrichContextOnEventNormalized;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Normalization\Enums\NormalizedEventStatus;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class EnrichContextJobTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
    }

    public function test_listener_dispatches_enrich_context_job_on_event_normalized(): void
    {
        Bus::fake();

        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        (new EnrichContextOnEventNormalized)->handle(new EventNormalized($event));

        Bus::assertDispatched(EnrichContextJob::class, fn (EnrichContextJob $job) => $job->normalizedEventId === $event->id);
    }

    public function test_job_handle_builds_snapshot_for_existing_event(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        (new EnrichContextJob($event->id))->handle(app(BuildEventContext::class));

        $this->assertDatabaseHas('event_context_snapshots', ['normalized_event_id' => $event->id]);
    }

    public function test_job_handle_no_ops_when_event_missing(): void
    {
        (new EnrichContextJob(99999))->handle(app(BuildEventContext::class));

        $this->assertSame(0, EventContextSnapshot::withoutGlobalScopes()->count());
    }

    public function test_job_unique_id_is_normalized_event_id(): void
    {
        $job = new EnrichContextJob(42);

        $this->assertSame('42', $job->uniqueId());
    }

    public function test_failed_method_marks_event_as_failed(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        (new EnrichContextJob($event->id))->failed(new \RuntimeException('boom'));

        $this->assertSame(NormalizedEventStatus::Failed, $event->fresh()->status);
    }

    public function test_failed_method_handles_missing_event(): void
    {
        (new EnrichContextJob(99999))->failed(new \RuntimeException('boom'));

        $this->assertTrue(true);
    }

    public function test_logs_snapshot_built_with_version_and_location_source(): void
    {
        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'payload_normalized_json' => ['location' => ['latitude' => 19.777777, 'longitude' => -98.888888]],
        ]);

        (new EnrichContextJob($event->id))->handle(app(BuildEventContext::class));
        (new EnrichContextJob($event->id))->handle(app(BuildEventContext::class));

        $entries = $this->systemLogEntries('context.snapshot.built');
        $this->assertCount(2, $entries);
        $first = $entries[0]['context'];
        $this->assertSame($event->id, $first['input']['normalized_event_id']);
        $this->assertSame('event_payload', $first['calc']['location_source']);
        $this->assertSame(1, $first['result']['context_version']);
        // La reconstrucción sube la versión (otros listeners de EventContextBuilt
        // también la suben, por eso no se fija el valor exacto).
        $this->assertGreaterThan(1, $entries[1]['context']['result']['context_version']);
        $this->assertSame(
            EventContextSnapshot::withoutGlobalScopes()->where('normalized_event_id', $event->id)->value('id'),
            $first['result']['snapshot_id'],
        );
        foreach (['geofence_matches', 'related_incidents', 'recent_events', 'recent_same_type', 'recent_high_severity', 'correlation_minutes', 'schedule_persisted', 'within_operating_hours', 'has_driver', 'position_stale', 'location_age_seconds'] as $key) {
            $this->assertArrayHasKey($key, $first['calc']);
        }
        $this->assertIsArray($first['result']['signals']);
        $this->assertSame(
            OperationalContextProfile::withoutGlobalScopes()->where('normalized_event_id', $event->id)->value('risk_level')?->value,
            $entries[1]['context']['result']['risk_level'],
        );
        $this->assertContains($first['result']['risk_level'], ['low', 'medium', 'high', 'critical']);
        $this->assertNoSensitiveDataLogged();
        $json = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('19.777777', $json);
        $this->assertStringNotContainsString('98.888888', $json);
    }

    public function test_snapshot_built_is_not_logged_when_the_transaction_rolls_back(): void
    {
        $this->app->instance(BuildOperationalContextProfile::class, new class extends BuildOperationalContextProfile
        {
            public function execute(EventContextSnapshot $snapshot): OperationalContextProfile
            {
                throw new \RuntimeException('profile failed');
            }
        });
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        try {
            app(BuildEventContext::class)->execute($event);
            $this->fail('The profile failure must propagate.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, EventContextSnapshot::withoutGlobalScopes()->where('normalized_event_id', $event->id)->count());
        $this->assertSystemNotLogged('context.snapshot.built');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_logs_unknown_location_source_without_any_position(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId, 'asset_id' => null]);

        (new EnrichContextJob($event->id))->handle(app(BuildEventContext::class));

        $c = $this->assertSystemLogged('context.snapshot.built');
        $this->assertSame('unknown', $c['calc']['location_source']);
        $this->assertNull($c['calc']['location_age_seconds']);
    }

    public function test_logs_skip_when_event_is_missing(): void
    {
        (new EnrichContextJob(99999))->handle(app(BuildEventContext::class));

        $c = $this->assertSystemLogged('context.enrich.skipped', fn (array $c) => $c['reason'] === 'normalized_event_missing');
        $this->assertSame(99999, $c['input']['normalized_event_id']);
        $this->assertNoSensitiveDataLogged();
    }
}
