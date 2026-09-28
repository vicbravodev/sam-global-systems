<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentNumberSequence;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * UI audit P1-11: the incident reference is a per-tenant sequence
 * (INC-00001, INC-00002…), never the global id that reveals how many
 * incidents the whole platform has.
 */
class IncidentNumberingTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    public function test_numbers_are_sequential_per_tenant_and_independent_of_the_global_id(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        $a1 = Incident::factory()->create(['team_id' => $teamA->id]);
        $b1 = Incident::factory()->create(['team_id' => $teamB->id]);
        $b2 = Incident::factory()->create(['team_id' => $teamB->id]);
        $a2 = Incident::factory()->create(['team_id' => $teamA->id]);

        $this->assertSame([1, 2], [$a1->number, $a2->number]);
        $this->assertSame([1, 2], [$b1->number, $b2->number]);
        $this->assertSame('INC-00002', $a2->reference());
        $this->assertSame('INC-00002', $b2->reference());
        $this->assertNotSame($a2->id, $a2->number);
    }

    public function test_numbering_never_touches_another_tenant(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        Incident::factory()->count(3)->create(['team_id' => $teamA->id]);

        $incident = $this->assertNoTenantLeak($teamB, fn () => Incident::factory()->create(['team_id' => $teamB->id]));

        $this->assertSame(1, $incident->number);
        $this->assertSame(3, (int) DB::table('teams')->where('id', $teamA->id)->value('last_incident_number'));
        $this->assertSame(1, (int) DB::table('teams')->where('id', $teamB->id)->value('last_incident_number'));
    }

    public function test_sequence_never_reuses_a_number_already_taken(): void
    {
        $team = Team::factory()->create();
        Incident::factory()->create(['team_id' => $team->id, 'number' => 41]);

        // Counter left behind (e.g. data imported out of band).
        DB::table('teams')->where('id', $team->id)->update(['last_incident_number' => 3]);

        $this->assertSame(42, IncidentNumberSequence::next($team->id));
    }

    public function test_migration_backfills_existing_incidents_per_team_in_id_order(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $a1 = Incident::factory()->create(['team_id' => $teamA->id]);
        $b1 = Incident::factory()->create(['team_id' => $teamB->id]);
        $a2 = Incident::factory()->create(['team_id' => $teamA->id]);

        $migration = require database_path('migrations/2026_09_30_100000_add_per_team_number_to_incidents_table.php');
        $migration->down();
        $migration->up();

        $numbers = DB::table('incidents')->pluck('number', 'id');

        $this->assertSame(1, (int) $numbers[$a1->id]);
        $this->assertSame(2, (int) $numbers[$a2->id]);
        $this->assertSame(1, (int) $numbers[$b1->id]);
        $this->assertSame(2, (int) DB::table('teams')->where('id', $teamA->id)->value('last_incident_number'));
        $this->assertSame(3, IncidentNumberSequence::next($teamA->id));
    }

    public function test_soft_deleted_numbers_are_not_reused(): void
    {
        $team = Team::factory()->create();
        $first = Incident::factory()->create(['team_id' => $team->id]);
        $first->delete();

        $second = Incident::factory()->create(['team_id' => $team->id]);

        $this->assertSame(2, $second->number);
    }

    public function test_duplicate_number_in_a_tenant_is_rejected_by_the_database(): void
    {
        $team = Team::factory()->create();
        Incident::factory()->create(['team_id' => $team->id, 'number' => 5]);

        $this->expectException(UniqueConstraintViolationException::class);

        Incident::factory()->create(['team_id' => $team->id, 'number' => 5]);
    }

    public function test_every_surface_uses_the_same_reference_and_inbox_search_finds_it(): void
    {
        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);

        $user = User::factory()->create();
        $team = $user->currentTeam;

        // Burn global ids in another tenant so id and number diverge.
        Incident::factory()->count(4)->create(['team_id' => Team::factory()->create()->id]);

        Incident::factory()->open()->create(['team_id' => $team->id, 'title' => 'Primero']);
        $target = Incident::factory()->open()->create(['team_id' => $team->id, 'title' => 'Segundo']);

        $this->assertSame('INC-00002', $target->reference());

        $this->actingAs($user)
            ->get(route('incidents.index', ['current_team' => $team->slug, 'q' => 'INC-00002']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('incidents', 1)
                ->where('incidents.0.id', 'INC-00002')
                ->where('incidents.0.incidentId', $target->id));

        $this->actingAs($user)
            ->get(route('incidents.show', ['current_team' => $team->slug, 'incident' => $target->id]))
            ->assertInertia(fn (Assert $page) => $page->where('incident.id', 'INC-00002'));

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $team->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('incidents.0.id', 'INC-00002'));

        $this->actingAs($user)
            ->getJson(route('palette.search', ['current_team' => $team->slug, 'q' => '2']))
            ->assertOk()
            ->assertJsonPath('incidents.0.reference', 'INC-00002')
            ->assertJsonPath('incidents.0.id', $target->id);
    }
}
