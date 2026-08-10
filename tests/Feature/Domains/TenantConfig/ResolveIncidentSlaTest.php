<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\TenantConfig\Actions\ResolveIncidentSla;
use App\Domains\TenantConfig\Models\TenantIncidentSla;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveIncidentSlaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_override_wins_over_global_catalog(): void
    {
        $team = Team::factory()->create();
        $priority = IncidentPriority::factory()->create(['sla_seconds' => 1800]);

        TenantIncidentSla::factory()->create([
            'team_id' => $team->id,
            'incident_priority_id' => $priority->id,
            'sla_seconds' => 180,
        ]);

        $this->assertSame(180, app(ResolveIncidentSla::class)->execute($team->id, $priority->id));
    }

    public function test_falls_back_to_global_catalog(): void
    {
        $team = Team::factory()->create();
        $priority = IncidentPriority::factory()->create(['sla_seconds' => 1800]);

        $this->assertSame(1800, app(ResolveIncidentSla::class)->execute($team->id, $priority->id));
    }

    public function test_returns_null_when_neither_defines_an_sla(): void
    {
        $team = Team::factory()->create();
        $priority = IncidentPriority::factory()->create(['sla_seconds' => null]);

        $this->assertNull(app(ResolveIncidentSla::class)->execute($team->id, $priority->id));
    }

    public function test_override_of_one_tenant_does_not_leak_to_another(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $priority = IncidentPriority::factory()->create(['sla_seconds' => 1800]);

        TenantIncidentSla::factory()->create([
            'team_id' => $teamA->id,
            'incident_priority_id' => $priority->id,
            'sla_seconds' => 120,
        ]);

        $resolver = app(ResolveIncidentSla::class);

        $this->assertSame(120, $resolver->execute($teamA->id, $priority->id));
        $this->assertSame(1800, $resolver->execute($teamB->id, $priority->id));
    }
}
