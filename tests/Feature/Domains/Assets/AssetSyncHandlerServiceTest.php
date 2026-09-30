<?php

namespace Tests\Feature\Domains\Assets;

use App\Contracts\AssetSyncHandler;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class AssetSyncHandlerServiceTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private function makeIntegration(): TenantIntegration
    {
        $user = User::factory()->create();
        // `code` is unique, so tests with two tenants share one provider row.
        $provider = IntegrationProvider::where('code', 'samsara')->first()
            ?? IntegrationProvider::factory()->samsara()->create();

        return TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $user->currentTeam->id,
            'provider_id' => $provider->id,
            'name' => 'Samsara Fleet',
            'status' => 'active',
            'auth_type' => 'api_key',
            'credentials_encrypted' => '',
        ]);
    }

    public function test_it_creates_the_asset_for_its_own_tenant(): void
    {
        AssetType::factory()->vehicle()->create();

        $integration = $this->makeIntegration();

        $outcome = app(AssetSyncHandler::class)->syncFromIntegration(
            $integration->team_id,
            $integration->id,
            ['external_id' => 'v-10', 'name' => 'Truck', 'asset_type_code' => 'vehicle'],
        );

        $this->assertTrue(
            Asset::withoutGlobalScopes()
                ->where('team_id', $integration->team_id)
                ->where('external_primary_id', 'v-10')
                ->exists(),
        );

        $this->assertSame('created', $outcome);

        $asset = Asset::withoutGlobalScopes()->where('external_primary_id', 'v-10')->sole();
        $applied = $this->systemLogEntries('assets.sync.asset_applied');
        $this->assertCount(1, $applied);
        $this->assertSame('debug', $applied[0]['level']);
        $this->assertSame($integration->team_id, $applied[0]['context']['input']['team_id']);
        $this->assertSame($asset->id, $applied[0]['context']['input']['asset_id']);
        $this->assertSame($integration->id, $applied[0]['context']['input']['integration_id']);
        $this->assertSame('created', $applied[0]['context']['calc']['branch']);
        $this->assertFalse($applied[0]['context']['calc']['devices_reported']);

        // A second sync of the same vehicle updates it.
        $this->assertSame('updated', app(AssetSyncHandler::class)->syncFromIntegration(
            $integration->team_id,
            $integration->id,
            ['external_id' => 'v-10', 'name' => 'Truck', 'asset_type_code' => 'vehicle'],
        ));

        $this->assertStringNotContainsString('Truck', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_it_swallows_a_cross_tenant_external_id_collision(): void
    {
        AssetType::factory()->vehicle()->create();

        $owner = $this->makeIntegration();
        $ownedAsset = Asset::factory()->create([
            'team_id' => $owner->team_id,
            'name' => 'Tenant A Truck',
        ]);
        AssetExternalReference::create([
            'asset_id' => $ownedAsset->id,
            'provider_id' => $owner->provider_id,
            'external_id' => 'v-11',
            'external_type' => 'vehicle',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $intruder = $this->makeIntegration();

        // Ingestion drives this handler one event at a time: a claimed external
        // id must be skipped, not blow up the event that carried it.
        $outcome = app(AssetSyncHandler::class)->syncFromIntegration(
            $intruder->team_id,
            $intruder->id,
            ['external_id' => 'v-11', 'name' => 'Hijacked Truck', 'asset_type_code' => 'vehicle'],
        );

        $this->assertEquals('Tenant A Truck', $ownedAsset->fresh()->name);
        $this->assertEquals(
            0,
            Asset::withoutGlobalScopes()->where('team_id', $intruder->team_id)->count(),
        );

        $this->assertSame('conflict', $outcome);

        $conflicts = $this->systemLogEntries('assets.sync.external_id_conflict');
        $this->assertCount(1, $conflicts);
        $context = $conflicts[0]['context'];
        $this->assertSame('skipped', $context['outcome']);
        $this->assertSame('owned_by_other_tenant', $context['reason']);
        // The tenant that ASKED for the id, never the owner.
        $this->assertSame($intruder->team_id, $context['input']['team_id']);
        $this->assertSame($owner->provider_id, $context['input']['provider_id']);
        $this->assertSame('v-11', $context['input']['external_id']);
        $this->assertSystemNotLogged('assets.sync.asset_applied');

        foreach ($this->systemLogEntries() as $entry) {
            $this->assertNotSame($owner->team_id, $entry['context']['input']['team_id'] ?? null);
            $this->assertNotSame($ownedAsset->id, $entry['context']['input']['asset_id'] ?? null);
        }
        $json = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('"owner', $json);
        $this->assertStringNotContainsString('Tenant A Truck', $json);
        $this->assertStringNotContainsString('Hijacked Truck', $json);
        $this->assertNoSensitiveDataLogged();
    }
}
