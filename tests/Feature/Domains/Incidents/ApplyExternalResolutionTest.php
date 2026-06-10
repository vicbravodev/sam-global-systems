<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\ApplyExternalResolution;
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Enums\EventRelationType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\ResolutionCode;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Jobs\ApplyExternalResolutionJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ApplyExternalResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
    }

    public function test_annotates_incident_without_closing_by_default(): void
    {
        $team = $this->makeTeam();
        [$incident, , $updateEvent] = $this->makePanicWithResolutionUpdate($team);

        (new ApplyExternalResolutionJob($updateEvent->id))->handle(app(ApplyExternalResolution::class));

        $fresh = $incident->fresh();
        $this->assertNotNull($fresh->external_resolved_at);
        $this->assertSame('2026-06-07T01:29:41+00:00', $fresh->external_resolved_at->toIso8601String());
        $this->assertSame(IncidentStatusCode::Open->value, $fresh->status->code);
        $this->assertNull($fresh->resolved_at);

        $this->assertDatabaseHas('incident_timelines', [
            'incident_id' => $incident->id,
            'entry_type' => TimelineEntryType::ExternallyResolved->value,
        ]);
    }

    public function test_closes_incident_when_tenant_opted_into_auto_close(): void
    {
        $team = $this->makeTeam();
        $this->setAutoCloseSetting($team, 'close');
        [$incident, , $updateEvent] = $this->makePanicWithResolutionUpdate($team);

        (new ApplyExternalResolutionJob($updateEvent->id))->handle(app(ApplyExternalResolution::class));

        $fresh = $incident->fresh();
        $this->assertNotNull($fresh->external_resolved_at);
        $this->assertSame(IncidentStatusCode::Resolved->value, $fresh->status->code);

        $this->assertDatabaseHas('incident_resolutions', [
            'incident_id' => $incident->id,
            'resolution_code' => ResolutionCode::ResolvedExternally->value,
        ]);
    }

    public function test_is_idempotent_and_does_not_duplicate_timeline_entries(): void
    {
        $team = $this->makeTeam();
        [$incident, , $updateEvent] = $this->makePanicWithResolutionUpdate($team);

        $action = app(ApplyExternalResolution::class);
        $action->execute($incident, $updateEvent);
        $action->execute($incident->fresh(), $updateEvent);

        $entries = IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::ExternallyResolved->value)
            ->count();

        $this->assertSame(1, $entries);
    }

    public function test_event_arriving_already_resolved_creates_annotated_incident_and_never_closes(): void
    {
        $team = $this->makeTeam();
        $this->setAutoCloseSetting($team, 'close');

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'payload_normalized_json' => [
                'event_type_code' => 'panic_emergency',
                'is_resolved' => true,
                'external_resolved_at' => '2026-06-07T01:29:41Z',
            ],
        ]);

        $incident = app(CreateIncidentFromEvent::class)->execute($event);

        $fresh = $incident->fresh();
        $this->assertNotNull($fresh->external_resolved_at);
        $this->assertSame(
            IncidentStatusCode::Open->value,
            $fresh->status->code,
            'an already-resolved panic still opens its incident — annotate only, never auto-close on creation',
        );

        $this->assertDatabaseHas('incident_timelines', [
            'incident_id' => $incident->id,
            'entry_type' => TimelineEntryType::ExternallyResolved->value,
        ]);
    }

    public function test_listener_dispatches_job_only_for_resolved_events(): void
    {
        Bus::fake([ApplyExternalResolutionJob::class]);

        $team = $this->makeTeam();

        $unresolved = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'payload_normalized_json' => ['is_resolved' => false],
        ]);
        event(new EventNormalized($unresolved));

        $withoutFlag = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'payload_normalized_json' => ['event_type_code' => 'panic_emergency'],
        ]);
        event(new EventNormalized($withoutFlag));

        Bus::assertNotDispatched(ApplyExternalResolutionJob::class);

        $resolved = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'payload_normalized_json' => ['is_resolved' => true],
        ]);
        event(new EventNormalized($resolved));

        Bus::assertDispatched(
            ApplyExternalResolutionJob::class,
            fn (ApplyExternalResolutionJob $job) => $job->normalizedEventId === $resolved->id,
        );
    }

    public function test_job_finds_incident_through_original_event_external_id(): void
    {
        $team = $this->makeTeam();
        [$incident, $originalEvent, $updateEvent] = $this->makePanicWithResolutionUpdate($team);

        // A second open incident for an unrelated asset must stay untouched.
        $unrelated = Incident::factory()->create(['team_id' => $team->id]);

        (new ApplyExternalResolutionJob($updateEvent->id))->handle(app(ApplyExternalResolution::class));

        $this->assertNotNull($incident->fresh()->external_resolved_at);
        $this->assertNull($unrelated->fresh()->external_resolved_at);
    }

    public function test_tenant_isolation_setting_and_incidents_of_other_teams_are_untouched(): void
    {
        $team = $this->makeTeam();
        $otherTeam = $this->makeTeam();

        // The OTHER team opted into auto-close; this team keeps the default.
        $this->setAutoCloseSetting($otherTeam, 'close');

        [$incident, , $updateEvent] = $this->makePanicWithResolutionUpdate($team);
        $otherIncident = Incident::factory()->create(['team_id' => $otherTeam->id]);

        (new ApplyExternalResolutionJob($updateEvent->id))->handle(app(ApplyExternalResolution::class));

        $fresh = $incident->fresh();
        $this->assertNotNull($fresh->external_resolved_at);
        $this->assertSame(
            IncidentStatusCode::Open->value,
            $fresh->status->code,
            'another tenant opting into auto-close must not close this tenant\'s incidents',
        );
        $this->assertNull($otherIncident->fresh()->external_resolved_at);
    }

    private function makeTeam(): Team
    {
        return User::factory()->create()->currentTeam;
    }

    /**
     * Build the panic scenario: an original AlertIncident event linked to an
     * open incident, plus a later resolution update sharing the provider
     * event id (`evt-panic-1`) but arriving as its own raw/normalized event.
     *
     * @return array{0: Incident, 1: NormalizedEvent, 2: NormalizedEvent}
     */
    private function makePanicWithResolutionUpdate(Team $team): array
    {
        $originalRaw = RawEvent::factory()->create([
            'team_id' => $team->id,
            'external_event_id' => 'evt-panic-1',
        ]);

        $originalEvent = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'raw_event_id' => $originalRaw->id,
            'payload_normalized_json' => [
                'event_type_code' => 'panic_emergency',
                'is_resolved' => false,
            ],
        ]);

        $incident = Incident::factory()->create([
            'team_id' => $team->id,
            'related_event_id' => $originalEvent->id,
        ]);

        IncidentEventLink::factory()->create([
            'incident_id' => $incident->id,
            'normalized_event_id' => $originalEvent->id,
            'relation_type' => EventRelationType::RootTrigger,
        ]);

        $updateRaw = RawEvent::factory()->create([
            'team_id' => $team->id,
            'external_event_id' => 'evt-panic-1',
        ]);

        $updateEvent = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'raw_event_id' => $updateRaw->id,
            'payload_normalized_json' => [
                'event_type_code' => 'panic_emergency',
                'is_resolved' => true,
                'external_resolved_at' => '2026-06-07T01:29:41Z',
            ],
        ]);

        return [$incident, $originalEvent, $updateEvent];
    }

    private function setAutoCloseSetting(Team $team, string $mode): void
    {
        TenantSetting::factory()->create([
            'team_id' => $team->id,
            'setting_key' => ApplyExternalResolution::SETTING_KEY,
            'setting_group' => SettingGroup::Operational,
            'value_json' => ['value' => $mode],
            'value_type' => SettingValueType::String,
        ]);
    }
}
