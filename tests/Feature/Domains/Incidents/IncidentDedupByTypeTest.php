<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Assets\Models\Asset;
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Enums\EventRelationType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * La deduplicación de incidentes solo agrupa eventos del mismo tipo de
 * incidente: un pánico nunca queda absorbido como evento de soporte de un
 * incidente de otra naturaleza abierto sobre el mismo activo.
 */
class IncidentDedupByTypeTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private int $teamId;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IncidentsSeeder::class);

        $this->teamId = User::factory()->create()->currentTeam->id;
        $this->asset = Asset::factory()->create(['team_id' => $this->teamId]);
    }

    public function test_panic_after_an_operational_incident_on_the_same_asset_opens_its_own_incident(): void
    {
        $afterHours = $this->createIncident('operational_alert');

        Event::fake([IncidentCreated::class]);

        $panic = $this->createIncident('panic_emergency', minutesLater: 5);

        $this->assertNotSame($afterHours->id, $panic->id);
        $this->assertSame('panic_emergency', $panic->fresh()->type->code);
        $this->assertSame('critical', $panic->fresh()->priority->code);
        Event::assertDispatched(IncidentCreated::class, fn (IncidentCreated $e) => $e->incident->id === $panic->id);
        $this->assertSame(2, Incident::query()->where('team_id', $this->teamId)->count());
    }

    public function test_two_panics_on_the_same_asset_inside_the_window_are_deduplicated(): void
    {
        $first = $this->createIncident('panic_emergency');

        Event::fake([IncidentCreated::class]);

        $second = $this->createIncident('panic_emergency', minutesLater: 5, event: $secondEvent);

        $this->assertSame($first->id, $second->id);
        Event::assertNotDispatched(IncidentCreated::class);
        $this->assertDatabaseHas('incident_event_links', [
            'incident_id' => $first->id,
            'normalized_event_id' => $secondEvent->id,
            'relation_type' => EventRelationType::SupportingEvent->value,
        ]);
    }

    public function test_a_more_severe_supporting_event_raises_the_incident_priority(): void
    {
        $incident = $this->createIncident('collision', priorityCode: 'medium');
        $this->assertSame('medium', $incident->fresh()->priority->code);

        $merged = $this->createIncident('collision', priorityCode: 'critical', minutesLater: 3);

        $this->assertSame($incident->id, $merged->id);
        $this->assertSame('critical', $incident->fresh()->priority->code);

        $entry = IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::PriorityChanged->value)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('Prioridad elevada por un nuevo evento', $entry->title);

        $this->assertSystemLogged('incidents.dedup.linked', fn (array $c) => $c['result']['existing_incident_id'] === $incident->id
            && $c['result']['priority_raised'] === true
            && $c['result']['previous_priority_code'] === 'medium'
            && $c['result']['new_priority_code'] === 'critical');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_less_severe_supporting_event_never_lowers_the_priority(): void
    {
        $incident = $this->createIncident('collision', priorityCode: 'critical');

        $this->createIncident('collision', priorityCode: 'low', minutesLater: 3);

        $this->assertSame('critical', $incident->fresh()->priority->code);
        $this->assertFalse(
            IncidentTimeline::query()
                ->where('incident_id', $incident->id)
                ->where('entry_type', TimelineEntryType::PriorityChanged->value)
                ->exists(),
        );

        $this->assertSystemLogged('incidents.dedup.linked', fn (array $c) => $c['result']['existing_incident_id'] === $incident->id
            && $c['result']['priority_raised'] === false
            && $c['result']['new_priority_code'] === null);
    }

    private function createIncident(
        string $typeCode,
        ?string $priorityCode = null,
        int $minutesLater = 0,
        ?NormalizedEvent &$event = null,
    ): Incident {
        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $this->asset->id,
            'occurred_at' => now()->addMinutes($minutesLater),
        ]);

        return app(CreateIncidentFromEvent::class)->execute($event, array_filter([
            'incident_type_code' => $typeCode,
            'priority_code' => $priorityCode,
        ]));
    }
}
