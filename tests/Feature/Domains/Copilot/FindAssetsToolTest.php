<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Assets\Enums\AssetCategory;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetType;
use App\Models\Team;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class FindAssetsToolTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, CopilotFixtures, RefreshDatabase, RunsCopilotTools;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        $this->team = Team::factory()->create();
    }

    private function unit(string $code, string $name, AssetCategory $category = AssetCategory::Vehicle, ?Team $team = null): Asset
    {
        return Asset::factory()->create([
            'team_id' => ($team ?? $this->team)->id,
            'asset_type_id' => AssetType::factory()->create(['category' => $category])->id,
            'code' => $code,
            'name' => $name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function find(array $args): array
    {
        return $this->callTool($this->team, ['assets.view'], 'find_assets', $args);
    }

    public function test_finds_by_partial_code(): void
    {
        $this->unit('T555', 'Kenworth T680');
        $this->unit('T-512', 'Freightliner Cascadia');
        $this->unit('R100', 'Remolque seco');

        $out = $this->find(['query' => 't5']);

        $codes = array_column($out['facts']['items'], 'code');
        sort($codes);
        $this->assertSame(['T-512', 'T555'], $codes);
        $this->assertSame(['code', 'name', 'category', 'lastSeenAt'], array_keys($out['facts']['items'][0]));
        $this->assertSame([], $this->toolCollector->blocks());
        $this->assertSystemLogged('copilot.tool.ran', fn ($c) => $c['input']['tool'] === 'find_assets');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_exact_code_comes_first(): void
    {
        $this->unit('T5550', 'Kenworth largo');
        $this->unit('T555', 'Kenworth corto');

        $out = $this->find(['query' => 'T-555']);

        $this->assertSame('T555', $out['facts']['items'][0]['code']);
    }

    public function test_finds_by_name_ignoring_case_and_accents(): void
    {
        $this->unit('T555', 'Kenworth T680');
        $this->unit('T600', 'Camión Volvo');

        $this->assertSame(['T555'], array_column($this->find(['query' => 'KENWORTH'])['facts']['items'], 'code'));
        $this->assertSame(['T600'], array_column($this->find(['query' => 'camion volvo'])['facts']['items'], 'code'));
    }

    public function test_respects_limit(): void
    {
        foreach (range(1, 8) as $i) {
            $this->unit("T50{$i}", "Kenworth {$i}");
        }

        $out = $this->find(['query' => 'kenworth', 'limit' => 3]);

        $this->assertCount(3, $out['facts']['items']);
        $this->assertSame(8, $out['facts']['total']);
    }

    public function test_filters_by_category(): void
    {
        $this->unit('T555', 'Tracto 5');
        $this->unit('R555', 'Remolque 5', AssetCategory::Trailer);

        $out = $this->find(['query' => '555', 'category' => AssetCategory::Trailer->value]);

        $this->assertSame(['R555'], array_column($out['facts']['items'], 'code'));
        $this->assertSame('trailer', $out['facts']['items'][0]['category']);
    }

    public function test_no_match_returns_a_notice(): void
    {
        $this->unit('T555', 'Kenworth');

        $out = $this->find(['query' => 'zz9']);

        $this->assertSame([], $out['facts']['items']);
        $this->assertSame('notice', $this->toolCollector->blocks()[0]['type']);
    }

    public function test_query_is_required_and_bounded(): void
    {
        $this->assertSame('argumentos inválidos', $this->find([])['error']);
        $this->assertSame('argumentos inválidos', $this->find(['query' => str_repeat('a', 41)])['error']);
        $this->assertSame('argumentos inválidos', $this->find(['query' => 'kw', 'limit' => 11])['error']);
    }

    public function test_never_returns_another_tenants_assets(): void
    {
        $this->unit('T555', 'Kenworth propio');
        $this->unit('T556', 'Kenworth ajeno', team: Team::factory()->create());

        $out = $this->assertNoTenantLeak($this->team, fn () => $this->find(['query' => 'kenworth']));

        $this->assertSame(['T555'], array_column($out['facts']['items'], 'code'));
        $this->assertStringNotContainsString('T556', json_encode($out));
    }
}
