<?php

namespace App\Domains\Assets\Commands;

use App\Domains\Assets\Enums\AssetCategory;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Models\Team;
use Illuminate\Console\Command;

class RecordAssetUsageMeters extends Command
{
    protected $signature = 'assets:record-usage-meters';

    protected $description = 'Record daily usage meters for monitored assets and active cameras per team';

    public function handle(RecordUsageEvent $recordUsage): int
    {
        $date = now()->toDateString();

        Team::query()->each(function (Team $team) use ($recordUsage, $date) {
            $this->recordMonitoredAssets($team, $recordUsage, $date);
            $this->recordActiveCameras($team, $recordUsage, $date);
        });

        $this->info('Asset usage meters recorded successfully.');

        return self::SUCCESS;
    }

    private function recordMonitoredAssets(Team $team, RecordUsageEvent $recordUsage, string $date): void
    {
        $count = Asset::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('status', '!=', AssetStatus::Inactive)
            ->count();

        if ($count > 0) {
            $recordUsage->execute(
                teamId: $team->id,
                meterCode: 'monitored_assets',
                quantity: $count,
                eventKey: "monitored_assets:{$team->id}:{$date}",
            );
        }
    }

    private function recordActiveCameras(Team $team, RecordUsageEvent $recordUsage, string $date): void
    {
        $count = Asset::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('status', '!=', AssetStatus::Inactive)
            ->whereHas('assetType', fn ($query) => $query->where('category', AssetCategory::Camera))
            ->count();

        if ($count > 0) {
            $recordUsage->execute(
                teamId: $team->id,
                meterCode: 'active_cameras',
                quantity: $count,
                eventKey: "active_cameras:{$team->id}:{$date}",
            );
        }
    }
}
