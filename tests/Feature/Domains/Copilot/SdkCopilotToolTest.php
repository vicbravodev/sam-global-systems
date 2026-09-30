<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Data\CopilotTurnScope;
use App\Domains\Copilot\Support\CopilotToolbox;
use App\Domains\Copilot\Support\CopilotTurnCollector;
use App\Domains\Copilot\Tools\AssetSummaryTool;
use App\Domains\Copilot\Tools\CopilotTool;
use App\Domains\Copilot\Tools\FleetOverviewTool;
use App\Domains\Copilot\Tools\Sdk\SdkCopilotTool;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class SdkCopilotToolTest extends TestCase
{
    use AssertsSystemLog, CopilotFixtures, RefreshDatabase;

    private CopilotTurnCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    private function tool(Team $team, User $user, string $name): SdkCopilotTool
    {
        $scope = CopilotTurnScope::fromTeam($team, app(AuthorizeAction::class)->resolvePermissions($user, $team), false);
        $this->collector = new CopilotTurnCollector;

        $tool = collect(app(CopilotToolbox::class)->for($scope, $this->collector))
            ->first(fn (SdkCopilotTool $t) => $t->name() === $name);

        $this->assertInstanceOf(SdkCopilotTool::class, $tool, "La tool {$name} no está en la caja.");

        return $tool;
    }

    public function test_runs_the_domain_tool_and_feeds_the_collector(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $asset = $this->truckWithTelemetry($team);

        $out = json_decode((string) $this->tool($team, $user, 'asset_location')
            ->handle(new Request(['asset_code' => 'T555'], 'call_1')), true);

        $this->assertArrayHasKey('facts', $out);
        $this->assertNotEmpty($this->collector->blocks());
        $this->assertSame('asset_location', $this->collector->tools()[0]['tool']);
        $this->assertSame('ok', $this->collector->tools()[0]['status']);
        $this->assertSame($asset->id, $this->collector->lastAssetId());
        $this->assertTrue($this->collector->hasResults());
        $this->assertSystemLogged('copilot.tool.ran', fn ($c) => $c['input']['tool'] === 'asset_location'
            && $c['input']['tool_call_id'] === 'call_1'
            && $c['input']['asset_id'] === $asset->id
            && $c['input']['period_days'] === 7
            && $c['result']['truncated'] === false);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_schema_and_name_reach_the_sdk(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');

        $unitTool = $this->tool($team, $user, 'asset_fuel');
        $unitSchema = (new ObjectSchema($unitTool->schema(new JsonSchemaTypeFactory)))->toSchema();
        $fleetSchema = (new ObjectSchema($this->tool($team, $user, 'fleet_overview')->schema(new JsonSchemaTypeFactory)))->toSchema();

        $this->assertSame('asset_fuel', ToolNameResolver::resolve($unitTool));
        $this->assertSame(['asset_code'], $unitSchema['required']);
        $this->assertSame(['asset_code', 'from', 'to', 'category'], array_keys($unitSchema['properties']));
        $this->assertSame([], $fleetSchema['required'] ?? []);
    }

    public function test_asset_code_is_matched_loosely_within_the_tenant(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $asset = $this->truckWithTelemetry($team, 'T-555');

        $out = json_decode((string) $this->tool($team, $user, 'asset_summary')
            ->handle(new Request(['asset_code' => 't 555'], 'c')), true);

        $this->assertArrayHasKey('facts', $out);
        $this->assertSame($asset->id, $this->collector->lastAssetId());
    }

    public function test_unknown_unit_returns_error_to_the_model(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');

        $out = json_decode((string) $this->tool($team, $user, 'asset_location')->handle(new Request(['asset_code' => 'ZZ999'], 'c')), true);

        $this->assertSame('unidad no encontrada', $out['error']);
        $this->assertSystemLogged('copilot.tool.invalid_args', fn ($c) => $c['reason'] === 'asset_not_found' && $c['input']['fields'] === ['asset_code']);
        $this->assertFalse($this->collector->hasResults());
        $this->assertNoSensitiveDataLogged();
    }

    public function test_missing_required_asset_code_is_rejected(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');

        $out = json_decode((string) $this->tool($team, $user, 'asset_engine')->handle(new Request([], 'c')), true);

        $this->assertSame('argumentos inválidos', $out['error']);
        $this->assertSystemLogged('copilot.tool.invalid_args', fn ($c) => $c['reason'] === 'validation_failed' && in_array('asset_code', $c['input']['fields'], true));
    }

    public function test_invalid_dates_are_rejected_without_touching_data(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');

        $out = json_decode((string) $this->tool($team, $user, 'fleet_overview')
            ->handle(new Request(['from' => 'ayer', 'to' => '2026-01-01T00:00:00Z'], 'c')), true);

        $this->assertSame('argumentos inválidos', $out['error']);
        $this->assertSystemLogged('copilot.tool.invalid_args', fn ($c) => $c['reason'] === 'validation_failed' && in_array('from', $c['input']['fields'], true));
        $this->assertSystemNotLogged('copilot.tool.ran');
        $this->assertFalse($this->collector->hasResults());
        $this->assertNoSensitiveDataLogged();
    }

    public function test_range_over_ninety_days_is_rejected(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $now = now()->toImmutable();

        $out = json_decode((string) $this->tool($team, $user, 'fleet_overview')->handle(new Request([
            'from' => $now->subDays(120)->toIso8601String(),
            'to' => $now->toIso8601String(),
        ], 'c')), true);

        $this->assertSame('argumentos inválidos', $out['error']);
        $this->assertSystemLogged('copilot.tool.invalid_args', fn ($c) => $c['reason'] === 'validation_failed' && in_array('from', $c['input']['fields'], true));
        $this->assertSystemNotLogged('copilot.tool.ran');
        $this->assertFalse($this->collector->hasResults());
    }

    public function test_from_after_to_is_rejected(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $now = now()->toImmutable();

        $out = json_decode((string) $this->tool($team, $user, 'fleet_overview')->handle(new Request([
            'from' => $now->subDay()->toIso8601String(),
            'to' => $now->subDays(3)->toIso8601String(),
        ], 'c')), true);

        $this->assertSame('argumentos inválidos', $out['error']);
        $this->assertSystemLogged('copilot.tool.invalid_args', fn ($c) => $c['reason'] === 'validation_failed');
    }

    public function test_explicit_period_reaches_the_log(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $now = now()->toImmutable();

        $this->tool($team, $user, 'fleet_overview')->handle(new Request([
            'from' => $now->subDays(30)->toIso8601String(),
            'to' => $now->toIso8601String(),
        ], 'c'));

        $this->assertSystemLogged('copilot.tool.ran', fn ($c) => $c['input']['period_days'] === 30 && $c['input']['asset_id'] === null);
    }

    public function test_tool_exception_becomes_an_error_for_the_model(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        $this->app->bind(AssetSummaryTool::class, fn () => new class implements CopilotTool
        {
            public function run(CopilotToolContext $context): CopilotToolResult
            {
                throw new RuntimeException('boom');
            }
        });

        $out = json_decode((string) $this->tool($team, $user, 'asset_summary')
            ->handle(new Request(['asset_code' => 'T555'], 'call_x')), true);

        $this->assertSame(['error' => 'no pude consultar Resumen de unidad'], $out);
        $this->assertSystemLogged('copilot.tool.failed', fn ($c) => $c['reason'] === 'tool_exception'
            && $c['input']['tool'] === 'asset_summary'
            && $c['error']['class'] === RuntimeException::class);
        $this->assertSame('error', $this->collector->tools()[0]['status']);
        $this->assertSystemNotLogged('copilot.tool.ran');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_domain_denial_is_recorded_and_logged(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');

        // A domain tool that refuses on its own, even though the toolbox let it through.
        $this->app->bind(FleetOverviewTool::class, fn () => new class implements CopilotTool
        {
            public function run(CopilotToolContext $context): CopilotToolResult
            {
                return CopilotToolResult::denied('fleet_overview', 'Estado de la flota', 'activos');
            }
        });

        $out = json_decode((string) $this->tool($team, $user, 'fleet_overview')->handle(new Request([], 'c')), true);

        $this->assertSame([], $out['facts']);
        $this->assertSame('denied', $this->collector->tools()[0]['status']);
        $this->assertSystemLogged('copilot.tool.denied', fn ($c) => $c['reason'] === 'missing_permission' && $c['input']['permission'] === 'assets.view');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_output_over_six_kilobytes_is_truncated(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');

        $this->app->bind(FleetOverviewTool::class, fn () => new class implements CopilotTool
        {
            public function run(CopilotToolContext $context): CopilotToolResult
            {
                $rows = array_map(fn (int $i) => ['unit' => "T{$i}", 'state' => 'En ruta', 'where' => "Carretera federal \"{$i}\", kilómetro {$i}, Nuevo León"], range(1, 120));

                return new CopilotToolResult('fleet_overview', 'Estado de la flota', facts: ['total' => 120, 'sample' => $rows], highlights: ['120 unidades.']);
            }
        });

        $raw = (string) $this->tool($team, $user, 'fleet_overview')->handle(new Request([], 'c'));
        $out = json_decode($raw, true);

        $this->assertLessThanOrEqual(6144, strlen($raw));
        $this->assertTrue($out['truncado']);
        $this->assertSame(['120 unidades.'], $out['highlights']);
        $this->assertSystemLogged('copilot.tool.ran', fn ($c) => $c['result']['truncated'] === true);
    }
}
