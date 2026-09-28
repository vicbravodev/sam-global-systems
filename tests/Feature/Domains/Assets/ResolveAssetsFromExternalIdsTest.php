<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Actions\ResolveAssetsFromExternalIds;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class ResolveAssetsFromExternalIdsTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private function linkAsset(Team $team, IntegrationProvider $provider, string $externalId): Asset
    {
        $asset = Asset::factory()->create(['team_id' => $team->id]);

        AssetExternalReference::factory()->create([
            'asset_id' => $asset->id,
            'provider_id' => $provider->id,
            'external_id' => $externalId,
        ]);

        return $asset;
    }

    public function test_it_resolves_a_whole_batch_keyed_by_external_id_and_skips_unknown_ids(): void
    {
        $team = Team::factory()->create();
        $provider = IntegrationProvider::factory()->create();

        $first = $this->linkAsset($team, $provider, '100');
        $second = $this->linkAsset($team, $provider, '200');

        $resolved = app(ResolveAssetsFromExternalIds::class)
            ->execute($provider->id, ['100', '200', '999', ''], $team->id);

        $this->assertSame(['100', '200'], array_map('strval', array_keys($resolved)));
        $this->assertTrue($resolved['100']->is($first));
        $this->assertTrue($resolved['200']->is($second));
    }

    public function test_it_never_resolves_an_external_id_owned_by_another_tenant(): void
    {
        $provider = IntegrationProvider::factory()->create();
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        $this->linkAsset($teamA, $provider, 'shared-looking-id');
        $own = $this->linkAsset($teamB, $provider, 'own-id');

        $resolved = $this->assertNoTenantLeak($teamB, fn () => app(ResolveAssetsFromExternalIds::class)
            ->execute($provider->id, ['shared-looking-id', 'own-id'], $teamB->id));

        $this->assertSame(['own-id'], array_keys($resolved));
        $this->assertTrue($resolved['own-id']->is($own));
    }

    public function test_the_query_count_does_not_grow_with_the_batch(): void
    {
        $team = Team::factory()->create();
        $provider = IntegrationProvider::factory()->create();

        $ids = [];

        foreach (range(1, 30) as $i) {
            $this->linkAsset($team, $provider, "v{$i}");
            $ids[] = "v{$i}";
        }

        DB::enableQueryLog();
        $resolved = app(ResolveAssetsFromExternalIds::class)->execute($provider->id, $ids, $team->id);

        $this->assertCount(30, $resolved);
        $this->assertCount(2, DB::getQueryLog());
    }

    public function test_it_omits_units_the_tenant_is_not_monitoring(): void
    {
        $team = Team::factory()->create();
        $provider = IntegrationProvider::factory()->create();

        $this->linkAsset($team, $provider, 'on');
        $pending = Asset::factory()->pendingMonitoring()->create(['team_id' => $team->id]);
        AssetExternalReference::factory()->create([
            'asset_id' => $pending->id,
            'provider_id' => $provider->id,
            'external_id' => 'pending',
        ]);
        $excluded = Asset::factory()->excluded()->create(['team_id' => $team->id]);
        AssetExternalReference::factory()->create([
            'asset_id' => $excluded->id,
            'provider_id' => $provider->id,
            'external_id' => 'excluded',
        ]);

        $resolved = app(ResolveAssetsFromExternalIds::class)
            ->execute($provider->id, ['on', 'pending', 'excluded'], $team->id);

        // A poll never spends provider quota, storage or alerts on a unit the
        // client has not switched on: only monitored assets resolve.
        $this->assertSame(['on'], array_keys($resolved));
    }
}
