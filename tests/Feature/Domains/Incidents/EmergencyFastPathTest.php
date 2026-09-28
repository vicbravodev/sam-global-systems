<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Assets\Jobs\DetectOfflineAssetsJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Jobs\CreateIncidentJob;
use App\Domains\Incidents\Jobs\OpenEmergencyIncidentJob;
use App\Domains\Incidents\Listeners\OpenEmergencyIncidentOnEventNormalized;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Decisión 2026-09-28: un pánico abre su incidente CRÍTICO (y con él la
 * llamada) en cuanto se normaliza, sin esperar a la IA. La IA llega después y
 * sólo enriquece ese mismo incidente.
 */
class EmergencyFastPathTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

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
    }

    public function test_non_emergencies_wait_for_the_ai(): void
    {
        Queue::fake();
        $event = $this->eventOfType('speeding', 'safety');

        app(OpenEmergencyIncidentOnEventNormalized::class)->handle(new EventNormalized($event));

        Queue::assertNotPushed(OpenEmergencyIncidentJob::class);
    }

    public function test_the_job_opens_a_critical_panic_incident_once(): void
    {
        Event::fake([IncidentCreated::class]);
        $event = $this->eventOfType('panic_button', 'emergency');

        app()->call([new OpenEmergencyIncidentJob($event->id, $this->team->id), 'handle']);
        app()->call([new OpenEmergencyIncidentJob($event->id, $this->team->id), 'handle']);

        $incident = Incident::withoutGlobalScopes()->with(['priority', 'type'])->sole();
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
    }
}
