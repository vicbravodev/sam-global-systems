<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Copilot\Data\CopilotTurnScope;
use App\Domains\Copilot\Support\CopilotToolbox;
use App\Domains\Copilot\Support\CopilotTurnCollector;
use App\Domains\Copilot\Tools\RankAssetsTool;
use App\Domains\Copilot\Tools\Sdk\SdkCopilotTool;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
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

        // Tenant B has every kind of data the fleet-wide tools read.
        $victimTruck = $this->truckWithTelemetry($other, 'T777');
        Incident::factory()->count(2)->create(['team_id' => $other->id, 'asset_id' => $victimTruck->id, 'opened_at' => now()->subDay()]);
        $panic = EventType::factory()->create(['code' => 'panic_button']);
        NormalizedEvent::factory()->count(3)->create(['team_id' => $other->id, 'asset_id' => $victimTruck->id, 'event_type_id' => $panic->id, 'occurred_at' => now()->subDay()]);
        foreach ([['Idle', 30], ['On', 20]] as [$state, $hoursAgo]) {
            AssetTelemetrySnapshot::factory()->create([
                'asset_id' => $victimTruck->id,
                'telemetry_type' => TelemetryType::Ignition,
                'data_json' => ['value' => $state],
                'recorded_at' => now()->subHours($hoursAgo),
            ]);
        }

        // Tenant A has one unit with nothing in the window.
        $this->truckWithTelemetry($team, 'A100');

        $scope = CopilotTurnScope::fromTeam($team, app(AuthorizeAction::class)->resolvePermissions($user, $team), false);
        $collector = new CopilotTurnCollector;
        $all = app(CopilotToolbox::class)->for($scope, $collector);
        $this->assertCount(15, $all);

        // suggest_followups reads no data; every other tool is a data tool.
        $tools = array_values(array_filter($all, fn ($t) => $t instanceof SdkCopilotTool));
        $byName = collect($tools)->keyBy(fn (SdkCopilotTool $t) => $t->name());

        $this->assertCount(14, $tools);

        // Runs as the requesting tenant and fails if anything of another tenant was touched.
        $this->assertNoTenantLeak($team, function () use ($tools, $byName) {
            foreach ($tools as $tool) {
                $out = json_decode((string) $tool->handle(new Request(['asset_code' => 'T777', 'query' => 'T777', 'metric' => 'idle_hours'], 'c')), true);

                if (in_array($tool->name(), ['find_assets', 'rank_assets'], true)) {
                    // Fleet-wide tools take no asset_code: they ignore it and still see only tenant A.
                    $this->assertArrayNotHasKey('error', $out, $tool->name());
                    $this->assertStringNotContainsString('T777', json_encode($out), $tool->name());
                } else {
                    $this->assertSame('unidad no encontrada', $out['error'], $tool->name());
                }

                $this->assertStringNotContainsString('T777', json_encode($out['facts'] ?? []));
            }

            // Fleet-wide tools without a unit only see the requesting tenant.
            foreach ($tools as $tool) {
                $out = json_decode((string) $tool->handle(new Request([], 'c')), true);

                $this->assertStringNotContainsString('T777', json_encode($out), $tool->name());
            }

            foreach (array_keys(RankAssetsTool::METRICS) as $metric) {
                $out = json_decode((string) $byName['rank_assets']->handle(new Request(['metric' => $metric], 'c')), true);

                // Tenant A's own truck may rank on fuel/distance; nothing else may appear.
                $this->assertSame([], array_diff(array_column($out['facts']['items'], 'code'), ['A100']), $metric);
                $this->assertStringNotContainsString('T777', json_encode($out), $metric);
            }

            $events = json_decode((string) $byName['search_events']->handle(new Request(['event_type' => 'panic_button'], 'c')), true);
            $this->assertSame(0, $events['facts']['total']);
            $this->assertSame(0, json_decode((string) $byName['search_events']->handle(new Request([], 'c')), true)['facts']['total']);

            $found = json_decode((string) $byName['find_assets']->handle(new Request(['query' => 'kenworth'], 'c')), true);
            $this->assertSame(['A100'], array_column($found['facts']['items'], 'code'));

            $timeline = json_decode((string) $byName['asset_timeline']->handle(new Request(['asset_code' => 'A100'], 'c')), true);
            $this->assertSame([], $timeline['facts']['items']);
        });

        $this->assertNotContains($victimTruck->id, array_filter([$collector->lastAssetId()]));
        $this->assertStringNotContainsString('T777', json_encode($collector->blocks()));
        $this->assertStringNotContainsString('T777', json_encode($collector->sources()));
    }
}
