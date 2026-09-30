<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Copilot\Data\CopilotTurnScope;
use App\Domains\Copilot\Support\CopilotToolbox;
use App\Domains\Copilot\Support\CopilotTurnCollector;
use App\Domains\Incidents\Models\Incident;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Fuga cross-tenant sobre el camino real de las tools del agente
 * (CLAUDE.md §2.1 punto 8): el modelo nombra una unidad de otro tenant y
 * ninguna tool puede leerla ni devolver sus datos.
 */
class CopilotAgentTenantLeakTest extends TestCase
{
    use AssertsTenantIsolation, CopilotFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    public function test_no_tool_reads_another_tenants_unit(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        [, $other] = $this->memberWithRole('supervisor');
        $victimTruck = $this->truckWithTelemetry($other, 'T777');
        Incident::factory()->count(2)->create(['team_id' => $other->id, 'asset_id' => $victimTruck->id]);

        $scope = CopilotTurnScope::fromTeam($team, app(AuthorizeAction::class)->resolvePermissions($user, $team), false);
        $collector = new CopilotTurnCollector;
        $tools = app(CopilotToolbox::class)->for($scope, $collector);

        $this->assertCount(10, $tools);

        // Runs as the requesting tenant and fails if anything of another tenant was touched.
        $this->assertNoTenantLeak($team, function () use ($tools) {
            foreach ($tools as $tool) {
                $out = json_decode((string) $tool->handle(new Request(['asset_code' => 'T777', 'query' => 'T777'], 'c')), true);

                $this->assertSame('unidad no encontrada', $out['error'], $tool->name());
                $this->assertStringNotContainsString('T777', json_encode($out['facts'] ?? []));
            }

            // Fleet-wide tools without a unit only see the requesting tenant.
            foreach ($tools as $tool) {
                $out = json_decode((string) $tool->handle(new Request([], 'c')), true);

                $this->assertStringNotContainsString('T777', json_encode($out), $tool->name());
            }
        });

        $this->assertNotContains($victimTruck->id, array_filter([$collector->lastAssetId()]));
        $this->assertStringNotContainsString('T777', json_encode($collector->blocks()));
        $this->assertStringNotContainsString('T777', json_encode($collector->sources()));
    }
}
