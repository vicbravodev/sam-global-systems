<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Assets\Jobs\DetectOfflineAssetsJob;
use App\Domains\Assets\Jobs\FreezeIncidentLocationTrailJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Automation\Jobs\RunAutomationWorkflowJob;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Jobs\CreateIncidentJob;
use App\Domains\Incidents\Jobs\OpenEmergencyIncidentJob;
use App\Domains\Incidents\Jobs\PlaceVerificationCallJob;
use App\Domains\Incidents\Jobs\RetryIncidentCreatedReactionJob;
use App\Domains\Incidents\Listeners\OpenEmergencyIncidentOnEventNormalized;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Jobs\SendNotificationJob;
use App\Domains\Notifications\Listeners\NotifyOnIncidentCreated;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Decisión 2026-09-28: un pánico abre su incidente CRÍTICO (y con él la
 * llamada) en cuanto se normaliza, sin esperar a la IA. La IA llega después y
 * sólo enriquece ese mismo incidente.
 */
class EmergencyFastPathTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IncidentsSeeder::class);
        $this->team = User::factory()->create()->currentTeam;
    }

    private function eventOfType(string $typeCode, string $categoryCode, array $rawPayload = [], ?Team $team = null): NormalizedEvent
    {
        $team ??= $this->team;
        $category = EventCategory::query()->where('code', $categoryCode)->first()
            ?? EventCategory::factory()->create(['code' => $categoryCode]);
        $type = EventType::query()->where('code', $typeCode)->first()
            ?? EventType::factory()->create(['code' => $typeCode, 'category_id' => $category->id]);
        $asset = Asset::factory()->create(['team_id' => $team->id]);
        $raw = RawEvent::factory()->create(['team_id' => $team->id, 'payload_json' => $rawPayload]);

        return NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'raw_event_id' => $raw->id,
            'asset_id' => $asset->id,
            'event_type_id' => $type->id,
            'event_category_id' => $category->id,
        ]);
    }

    public function test_a_panic_dispatches_the_fast_path_as_soon_as_it_is_normalized(): void
    {
        Queue::fake();
        $event = $this->eventOfType('panic_button', 'emergency');

        app(OpenEmergencyIncidentOnEventNormalized::class)->handle(new EventNormalized($event));

        Queue::assertPushed(OpenEmergencyIncidentJob::class, fn (OpenEmergencyIncidentJob $job) => $job->normalizedEventId === $event->id
            && $job->teamId === $this->team->id
            && $job->priorityCode === 'critical');

        $c = $this->assertSystemLogged('incidents.emergency.fast_path');
        $this->assertSame('ok', $c['outcome']);
        $this->assertSame($event->id, $c['input']['normalized_event_id']);
        $this->assertSame('emergency_code', $c['calc']['trigger']);
        $this->assertNull($c['calc']['was_in_motion']);
        $this->assertSame('critical', $c['result']['priority_code']);
        $this->assertTrue($c['result']['job_requested']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_mapping_rule_that_overrides_the_category_to_emergency_takes_the_fast_path(): void
    {
        Queue::fake();
        // El tipo vive en `safety`, pero la regla de mapeo lo reclasificó a
        // `emergency` (mapped_category_id): manda la categoría del evento
        // normalizado, la misma que usó la normalización para no descartarlo.
        $event = $this->eventOfType('harsh_event_custom', 'safety');
        $emergency = EventCategory::query()->where('code', 'emergency')->first()
            ?? EventCategory::factory()->create(['code' => 'emergency']);
        $event->update(['event_category_id' => $emergency->id]);

        app(OpenEmergencyIncidentOnEventNormalized::class)->handle(new EventNormalized($event->fresh()));

        Queue::assertPushed(OpenEmergencyIncidentJob::class, fn (OpenEmergencyIncidentJob $job) => $job->normalizedEventId === $event->id);

        $c = $this->assertSystemLogged('incidents.emergency.fast_path');
        $this->assertSame('ok', $c['outcome']);
        $this->assertSame('emergency', $c['input']['category_code']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_non_emergencies_wait_for_the_ai(): void
    {
        Queue::fake();
        $event = $this->eventOfType('speeding', 'safety');

        app(OpenEmergencyIncidentOnEventNormalized::class)->handle(new EventNormalized($event));

        Queue::assertNotPushed(OpenEmergencyIncidentJob::class);

        $c = $this->assertSystemLogged('incidents.emergency.fast_path', fn (array $c) => ($c['reason'] ?? null) === 'not_emergency');
        $this->assertSame('skipped', $c['outcome']);
        $this->assertSame('debug', $this->systemLogEntries('incidents.emergency.fast_path')[0]['level']);
    }

    public function test_the_job_opens_a_critical_panic_incident_once(): void
    {
        Event::fake([IncidentCreated::class]);
        $event = $this->eventOfType('panic_button', 'emergency');

        app()->call([new OpenEmergencyIncidentJob($event->id, $this->team->id), 'handle']);
        app()->call([new OpenEmergencyIncidentJob($event->id, $this->team->id), 'handle']);

        $incident = Incident::withoutGlobalScopes()->with(['priority', 'type'])->sole();
        $c = $this->assertSystemLogged('incidents.emergency.job_skipped', fn (array $c) => ($c['reason'] ?? null) === 'incident_exists');
        $this->assertSame($incident->id, $c['result']['incident_id']);
        $this->assertSame($this->team->id, (int) $incident->team_id);
        $this->assertSame('critical', $incident->priority->code);
        $this->assertSame('panic_emergency', $incident->type->code);
        $this->assertTrue($incident->metadata_json['emergency_fast_path']);
        Event::assertDispatchedTimes(IncidentCreated::class, 1);
    }

    public function test_the_later_ai_decision_enriches_the_same_incident_instead_of_opening_another(): void
    {
        Event::fake([IncidentCreated::class]);
        $event = $this->eventOfType('panic_button', 'emergency');

        app()->call([new OpenEmergencyIncidentJob($event->id, $this->team->id), 'handle']);
        app()->call([new CreateIncidentJob($event->id, ['priority_code' => 'medium']), 'handle']);

        $incident = Incident::withoutGlobalScopes()->with('priority')->sole();
        $this->assertSame('critical', $incident->priority->code, 'La IA nunca baja la prioridad del pánico.');
    }

    public function test_a_job_whose_team_does_not_match_its_event_does_nothing(): void
    {
        $other = Team::factory()->create();
        $event = $this->eventOfType('panic_button', 'emergency');

        $this->assertNoTenantLeak(
            $other,
            fn () => app()->call([new OpenEmergencyIncidentJob($event->id, $other->id), 'handle']),
        );

        $this->assertSame(0, Incident::withoutGlobalScopes()->count());

        $c = $this->assertSystemLogged('incidents.emergency.job_skipped', fn (array $c) => ($c['reason'] ?? null) === 'team_mismatch');
        $this->assertSame(['team_matches' => false], $c['calc']);
        // El evento es del otro tenant respecto al team del job: nunca su id.
        $this->assertArrayNotHasKey('normalized_event_id', $c['input'] ?? []);
        $this->assertStringNotContainsString('"team_id":'.$other->id.',', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_unit_that_goes_silent_in_motion_opens_a_high_incident(): void
    {
        Queue::fake();
        $moving = $this->eventOfType(DetectOfflineAssetsJob::EVENT_TYPE_CODE, 'maintenance', ['was_in_motion' => true]);
        $parked = $this->eventOfType(DetectOfflineAssetsJob::EVENT_TYPE_CODE, 'maintenance', ['was_in_motion' => false]);

        app(OpenEmergencyIncidentOnEventNormalized::class)->handle(new EventNormalized($moving));
        app(OpenEmergencyIncidentOnEventNormalized::class)->handle(new EventNormalized($parked));

        Queue::assertPushed(OpenEmergencyIncidentJob::class, 1);
        Queue::assertPushed(OpenEmergencyIncidentJob::class, fn (OpenEmergencyIncidentJob $job) => $job->normalizedEventId === $moving->id
            && $job->priorityCode === 'high');

        $c = $this->assertSystemLogged('incidents.emergency.fast_path', fn (array $c) => ($c['outcome'] ?? null) === 'ok');
        $this->assertSame('offline_in_motion', $c['calc']['trigger']);
        $this->assertTrue($c['calc']['was_in_motion']);
        $this->assertSame('high', $c['result']['priority_code']);

        $c = $this->assertSystemLogged('incidents.emergency.fast_path', fn (array $c) => ($c['reason'] ?? null) === 'offline_parked');
        $this->assertSame($parked->id, $c['input']['normalized_event_id']);
        $this->assertFalse($c['calc']['was_in_motion']);
    }

    public function test_a_failing_notification_never_loses_the_panic_nor_its_verification(): void
    {
        Bus::fake([SendNotificationJob::class, PlaceVerificationCallJob::class, RunAutomationWorkflowJob::class, FreezeIncidentLocationTrailJob::class]);
        Queue::fake([RetryIncidentCreatedReactionJob::class]);
        $this->partialMock(NotifyOnIncidentCreated::class, function (MockInterface $mock) {
            $mock->shouldReceive('react')->once()->andThrow(new RuntimeException('twilio down'));
        });
        $event = $this->eventOfType('panic_button', 'emergency');

        app()->call([new OpenEmergencyIncidentJob($event->id, $this->team->id), 'handle']);

        $incident = Incident::withoutGlobalScopes()->with('priority')->sole();
        $this->assertSame('critical', $incident->priority->code, 'El pánico sigue abierto aunque su aviso falle.');

        // La verificación (sin teléfonos: escalada inmediata) corrió igual.
        $this->assertTrue(IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::VerificationCall)
            ->exists());

        $this->assertSystemLogged('incidents.created_reaction.failed', fn (array $c) => $c['input']['reaction'] === 'NotifyOnIncidentCreated'
            && $c['result']['retry_queue'] === 'notifications');
        Queue::assertPushedOn('notifications', RetryIncidentCreatedReactionJob::class);
        $this->assertNoSensitiveDataLogged();
    }
}
