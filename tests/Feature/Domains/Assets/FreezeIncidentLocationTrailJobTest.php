<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Jobs\FreezeIncidentLocationTrailJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Incidents\Enums\EvidenceType;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEvidence;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class FreezeIncidentLocationTrailJobTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private function incidentWithTrail(): Incident
    {
        $team = Team::factory()->create();
        $asset = Asset::factory()->create(['team_id' => $team->id]);
        $incident = Incident::factory()->create([
            'team_id' => $team->id,
            'asset_id' => $asset->id,
            'opened_at' => now()->subHour(),
        ]);

        foreach ([-45, -20, -1, 10, 29, 40] as $offset) {
            AssetLocationSnapshot::factory()->for($asset)->create(['recorded_at' => $incident->opened_at->copy()->addMinutes($offset)]);
        }

        return $incident;
    }

    private function freeze(Incident $incident): void
    {
        app()->call([new FreezeIncidentLocationTrailJob($incident->id, $incident->team_id), 'handle']);
    }

    public function test_it_freezes_the_points_within_the_window_once(): void
    {
        $incident = $this->incidentWithTrail();

        $this->assertNoTenantLeak($incident->team_id, fn () => $this->freeze($incident));
        $this->freeze($incident);

        $evidence = IncidentEvidence::query()->where('incident_id', $incident->id)->where('evidence_type', EvidenceType::LocationTrail)->sole();
        // -20, -1, +10, +29 are within ±30 min; -45 and +40 are not.
        $this->assertCount(4, $evidence->metadata_json['points']);
    }

    public function test_a_mismatched_team_is_ignored(): void
    {
        $incident = $this->incidentWithTrail();

        app()->call([new FreezeIncidentLocationTrailJob($incident->id, Team::factory()->create()->id), 'handle']);

        $this->assertSame(0, IncidentEvidence::query()->count());
    }

    public function test_creating_an_incident_schedules_the_freeze_after_the_window(): void
    {
        Queue::fake();
        $incident = $this->incidentWithTrail();

        IncidentCreated::dispatch($incident);

        Queue::assertPushed(FreezeIncidentLocationTrailJob::class, fn (FreezeIncidentLocationTrailJob $job) => $job->incidentId === $incident->id
            && $job->teamId === $incident->team_id
            && $job->delay->greaterThan(now()->addMinutes(29)));
    }
}
