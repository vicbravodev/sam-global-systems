<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Jobs\CreateIncidentJob;
use App\Domains\Incidents\Listeners\CreateIncidentOnDecisionMade;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Fakes\FakeDecisionMadeEvent;
use Tests\TestCase;

class CreateIncidentOnDecisionMadeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
    }

    public function test_incident_outcome_dispatches_create_incident_job(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create(['team_id' => $user->currentTeam->id]);

        app(CreateIncidentOnDecisionMade::class)->handle(new FakeDecisionMadeEvent(
            outcome: 'INCIDENT',
            normalized_event_id: $event->id,
            decision_id: 99,
            priority_code: 'critical',
            incident_type_code: 'panic_emergency',
        ));

        Bus::assertDispatched(CreateIncidentJob::class, function (CreateIncidentJob $job) use ($event) {
            return $job->normalizedEventId === $event->id
                && ($job->context['decision_id'] ?? null) === 99
                && ($job->context['priority_code'] ?? null) === 'critical';
        });
    }

    public function test_non_actionable_outcome_is_ignored(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create(['team_id' => $user->currentTeam->id]);

        app(CreateIncidentOnDecisionMade::class)->handle(new FakeDecisionMadeEvent(
            outcome: 'IGNORE',
            normalized_event_id: $event->id,
        ));

        Bus::assertNotDispatched(CreateIncidentJob::class);
    }

    public function test_listener_run_via_job_creates_only_one_incident_for_repeat_dispatch(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $asset = $this->makeAsset($team);
        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'asset_id' => $asset->id,
        ]);

        // Simulate two decisions firing for the same normalized event quickly.
        (new CreateIncidentJob($event->id, ['incident_type_code' => 'collision']))
            ->handle(app(CreateIncidentFromEvent::class));
        (new CreateIncidentJob($event->id, ['incident_type_code' => 'collision']))
            ->handle(app(CreateIncidentFromEvent::class));

        $this->assertSame(1, Incident::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('related_event_id', $event->id)
            ->count(),
            'Same normalized event must not create more than one incident — the dedup guard in CreateIncidentFromEvent must reuse the existing one.',
        );
    }

    private function makeAsset(Team $team): Asset
    {
        $type = AssetType::factory()->vehicle()->create();

        return Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
            'name' => 'Test Truck',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
