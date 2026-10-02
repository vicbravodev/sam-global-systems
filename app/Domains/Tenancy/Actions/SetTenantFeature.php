<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Enums\FeatureSource;
use App\Domains\Tenancy\Events\TenantFeatureChanged;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Models\Team;
use App\Support\LoggableCode;
use App\Support\SystemLog;
use App\Support\TenantContext;

/**
 * Toggles a tenant feature (and optionally its limits) as a manual override.
 * When $limits is null the existing limits are preserved — toggling enabled does
 * not wipe a configured cap.
 */
class SetTenantFeature
{
    /**
     * @param  array<string, mixed>|null  $limits
     */
    public function execute(Team $team, string $featureKey, bool $enabled, ?array $limits = null): TenantFeature
    {
        // Entrar en el tenant objetivo: desde /admin el usuario tiene su propio
        // team actual y el scope no vería la feature existente (→ insert
        // duplicado contra el unique (team_id, feature_key)). Ver §2.1.
        $feature = TenantContext::for($team->id, fn () => TenantFeature::query()->firstOrNew([
            'team_id' => $team->id,
            'feature_key' => $featureKey,
        ]));

        $previous = $feature->exists ? $feature->enabled : null;
        $feature->enabled = $enabled;
        $feature->source = FeatureSource::ManualOverride;

        if ($limits !== null) {
            $feature->limits_json = $limits;
        }

        $feature->save();

        TenantFeatureChanged::dispatch($team->id, $featureKey, $enabled);

        TenantContext::for($team->id, fn () => SystemLog::ok('tenancy.feature.changed', input: [
            'team_id' => $team->id,
            'feature_key' => LoggableCode::guard($featureKey),
            'actor_id' => auth()->id(),
        ], result: [
            'previous_enabled' => $previous,
            'enabled' => $enabled,
            'limits_changed' => $limits !== null,
            'source' => FeatureSource::ManualOverride->value,
        ]));

        return $feature;
    }
}
