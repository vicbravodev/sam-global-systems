<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Access\Actions\AssignRoleToMember;
use App\Domains\Assets\Enums\AssetCategory;
use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Assets\Models\AssetType;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;

/**
 * Shared fixtures: a tenant user with a role and a unit with GPS, fuel and
 * engine telemetry, all created inside the same tenant.
 */
trait CopilotFixtures
{
    /**
     * @return array{0: User, 1: Team}
     */
    protected function memberWithRole(string $roleCode): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $membership = Membership::query()
            ->where('user_id', $user->id)
            ->where('team_id', $team->id)
            ->firstOrFail();

        app(AssignRoleToMember::class)->execute($membership, $roleCode);

        return [$user, $team];
    }

    /**
     * A second user joining an existing tenant with the given role.
     */
    protected function joinTeamWithRole(Team $team, string $roleCode): User
    {
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => TeamRole::Member->value]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        $membership = Membership::query()
            ->where('user_id', $user->id)
            ->where('team_id', $team->id)
            ->firstOrFail();

        app(AssignRoleToMember::class)->execute($membership, $roleCode);

        return $user->refresh();
    }

    protected function truckWithTelemetry(Team $team, string $code = 'T555', AssetCategory $category = AssetCategory::Vehicle): Asset
    {
        $type = AssetType::factory()->create(['category' => $category]);

        $asset = Asset::factory()->create([
            'team_id' => $team->id,
            'asset_type_id' => $type->id,
            'code' => $code,
            'name' => "Kenworth {$code}",
        ]);

        foreach ([[40, 65.0], [20, 80.0], [2, 72.5]] as [$minutesAgo, $speed]) {
            AssetLocationSnapshot::factory()->create([
                'asset_id' => $asset->id,
                'latitude' => 19.4326,
                'longitude' => -99.1332,
                'formatted_location' => 'Av. Insurgentes Sur, CDMX',
                'speed' => $speed,
                'recorded_at' => now()->subMinutes($minutesAgo),
            ]);
        }

        foreach ([[3, 80.0], [2, 55.0], [1, 90.0]] as [$daysAgo, $fuel]) {
            AssetTelemetrySnapshot::factory()->create([
                'asset_id' => $asset->id,
                'telemetry_type' => TelemetryType::Fuel,
                'data_json' => ['value' => $fuel, 'unit' => '%'],
                'recorded_at' => now()->subDays($daysAgo),
            ]);
        }

        AssetTelemetrySnapshot::factory()->create([
            'asset_id' => $asset->id,
            'telemetry_type' => TelemetryType::Ignition,
            'data_json' => ['value' => 'On', 'unit' => null],
            'recorded_at' => now()->subMinutes(5),
        ]);

        foreach ([[2, 120500.0], [0, 120910.0]] as [$daysAgo, $km]) {
            AssetTelemetrySnapshot::factory()->create([
                'asset_id' => $asset->id,
                'telemetry_type' => TelemetryType::Odometer,
                'data_json' => ['value' => $km, 'unit' => 'km'],
                'recorded_at' => now()->subDays($daysAgo)->subMinutes(1),
            ]);
        }

        return $asset;
    }
}
