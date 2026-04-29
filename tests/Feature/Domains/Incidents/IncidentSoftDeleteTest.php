<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Models\Incident;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
    }

    public function test_soft_deleted_incident_excluded_from_queries_and_accessible_via_with_trashed(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $incident = Incident::factory()->create(['team_id' => $team->id, 'title' => 'soft-deleted']);

        $incident->delete();

        $this->actingAs($user);

        $this->assertSame(0, Incident::query()->count());
        $this->assertSame(1, Incident::withTrashed()->count());

        $found = Incident::withTrashed()->find($incident->id);
        $this->assertNotNull($found);
        $this->assertNotNull($found->deleted_at);
    }
}
