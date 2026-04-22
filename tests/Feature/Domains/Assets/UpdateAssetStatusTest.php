<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Actions\UpdateAssetStatus;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Events\AssetStatusChanged;
use App\Domains\Assets\Events\AssetStatusChangedBroadcast;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class UpdateAssetStatusTest extends TestCase
{
    use RefreshDatabase;

    private function createAsset(AssetStatus $status = AssetStatus::Active): Asset
    {
        $user = User::factory()->create();
        $assetType = AssetType::factory()->vehicle()->create();

        return Asset::withoutGlobalScopes()->create([
            'team_id' => $user->currentTeam->id,
            'asset_type_id' => $assetType->id,
            'name' => 'Test Vehicle',
            'status' => $status,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    public function test_it_updates_asset_status_and_dispatches_event(): void
    {
        Event::fake([AssetStatusChanged::class, AssetStatusChangedBroadcast::class]);

        $asset = $this->createAsset(AssetStatus::Active);

        $action = app(UpdateAssetStatus::class);
        $action->execute($asset, AssetStatus::Offline);

        $asset->refresh();

        $this->assertEquals(
            AssetStatus::Offline,
            $asset->status,
            'Asset status should be updated to the new value',
        );

        Event::assertDispatched(AssetStatusChanged::class, function ($event) use ($asset) {
            return $event->assetId === $asset->id
                && $event->previousStatus === 'active'
                && $event->newStatus === 'offline';
        });
    }

    public function test_it_broadcasts_asset_status_changed(): void
    {
        Event::fake([AssetStatusChanged::class, AssetStatusChangedBroadcast::class]);

        $asset = $this->createAsset(AssetStatus::Active);

        $action = app(UpdateAssetStatus::class);
        $action->execute($asset, AssetStatus::Alert);

        Event::assertDispatched(AssetStatusChangedBroadcast::class, function ($event) use ($asset) {
            return $event->assetId === $asset->id
                && $event->name === 'Test Vehicle'
                && $event->previousStatus === 'active'
                && $event->newStatus === 'alert';
        });
    }

    public function test_it_does_not_dispatch_event_when_status_unchanged(): void
    {
        Event::fake([AssetStatusChanged::class, AssetStatusChangedBroadcast::class]);

        $asset = $this->createAsset(AssetStatus::Active);

        $action = app(UpdateAssetStatus::class);
        $action->execute($asset, AssetStatus::Active);

        Event::assertNotDispatched(
            AssetStatusChanged::class,
            'No domain event should be dispatched when the status remains the same',
        );

        Event::assertNotDispatched(
            AssetStatusChangedBroadcast::class,
            'No broadcast event should be dispatched when the status remains the same',
        );
    }
}
