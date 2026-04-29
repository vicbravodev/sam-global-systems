<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Models\Incident;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
    }

    public function test_belongs_to_tenant_scope_filters_queries_to_current_team(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        Incident::factory()->create(['team_id' => $userA->currentTeam->id, 'title' => 'A-1']);
        Incident::factory()->create(['team_id' => $userA->currentTeam->id, 'title' => 'A-2']);
        Incident::factory()->create(['team_id' => $userB->currentTeam->id, 'title' => 'B-1']);

        $this->actingAs($userA);
        $this->assertSame(2, Incident::query()->count());
        $this->assertSame(['A-1', 'A-2'], Incident::query()->orderBy('id')->pluck('title')->all());

        $this->actingAs($userB);
        $this->assertSame(1, Incident::query()->count());
        $this->assertSame('B-1', Incident::query()->value('title'));
    }
}
