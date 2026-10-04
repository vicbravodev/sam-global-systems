<?php

namespace App\Domains\Drivers\Actions;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Support\TenantContext;

/**
 * The tenant's HOS monitoring config, or null when the `hos_monitoring`
 * feature is not explicitly enabled (opt-in: no row means off).
 */
class ResolveHosMonitoringConfig
{
    public function __construct(private readonly TenantConfigResolver $settings) {}

    public function execute(int $teamId): ?HosMonitoringConfig
    {
        return TenantContext::for($teamId, function () use ($teamId): ?HosMonitoringConfig {
            $enabled = TenantFeature::query()
                ->where('team_id', $teamId)
                ->where('feature_key', HosMonitoringConfig::FEATURE_KEY)
                ->where('enabled', true)
                ->exists();

            if (! $enabled) {
                return null;
            }

            $stored = $this->settings->resolve($teamId, HosMonitoringConfig::SETTING_KEY, []);

            return HosMonitoringConfig::fromArray(is_array($stored) ? $stored : [], (array) config('hos.defaults'));
        });
    }
}
