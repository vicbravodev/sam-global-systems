<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Assets\Actions\ResolveAssetsFromExternalIds;
use App\Domains\Drivers\Data\HosEnrollment;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Integrations\Data\HosClockReading;
use App\Domains\Integrations\Models\TenantIntegration;

/**
 * Which HOS clock rows SAM watches (spec §3.4, approach A): the driver must
 * be in a MONITORED unit of the tenant (billing is the unit-day) that is not
 * excluded and that matches a chosen tag — the unit's or the driver's, a
 * child tag counting for its parent — or was added by hand.
 */
class ResolveHosEnrollment
{
    public function __construct(
        private readonly ResolveAssetsFromExternalIds $resolveAssets,
        private readonly ResolveDriversFromExternalIds $resolveDrivers,
    ) {}

    /**
     * @param  array<int, HosClockReading>  $readings
     * @param  array<int, array{id: string, name: string, parent_id: string|null, vehicle_ids: array<int, string>, driver_ids: array<int, string>}>  $tags
     */
    public function execute(TenantIntegration $integration, HosMonitoringConfig $config, array $readings, array $tags): HosEnrollment
    {
        [$taggedVehicles, $taggedDrivers] = $this->tagMembers($tags, $config->tagIds);

        $assets = $this->resolveAssets->execute(
            $integration->provider_id,
            array_values(array_filter(array_map(fn (HosClockReading $r) => $r->externalVehicleId, $readings), fn ($v) => $v !== null)),
            $integration->team_id,
        );
        $drivers = $this->resolveDrivers->execute(
            $integration->provider_id,
            array_map(fn (HosClockReading $r) => $r->externalDriverId, $readings),
            $integration->team_id,
        );

        $included = array_flip($config->includedAssetIds);
        $excluded = array_flip($config->excludedAssetIds);
        $enrolled = [];
        $skipped = [];

        foreach ($readings as $reading) {
            $reason = match (true) {
                $reading->externalVehicleId === null => 'no_vehicle',
                ! isset($drivers[$reading->externalDriverId]) => 'driver_unresolved',
                ! isset($assets[$reading->externalVehicleId]) => 'vehicle_unresolved',
                isset($excluded[$assets[$reading->externalVehicleId]->id]) => 'excluded',
                isset($included[$assets[$reading->externalVehicleId]->id]),
                isset($taggedVehicles[$reading->externalVehicleId]),
                isset($taggedDrivers[$reading->externalDriverId]) => null,
                default => 'no_match',
            };

            if ($reason !== null) {
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;

                continue;
            }

            $enrolled[] = [
                'reading' => $reading,
                'driver' => $drivers[$reading->externalDriverId],
                'asset' => $assets[$reading->externalVehicleId],
            ];
        }

        return new HosEnrollment($enrolled, $skipped);
    }

    /**
     * Members of the chosen tags and of all their descendants.
     *
     * @param  array<int, array{id: string, name: string, parent_id: string|null, vehicle_ids: array<int, string>, driver_ids: array<int, string>}>  $tags
     * @param  array<int, string>  $chosen
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    private function tagMembers(array $tags, array $chosen): array
    {
        $selected = array_fill_keys($chosen, true);

        do {
            $grew = false;

            foreach ($tags as $tag) {
                if (! isset($selected[$tag['id']]) && $tag['parent_id'] !== null && isset($selected[$tag['parent_id']])) {
                    $selected[$tag['id']] = true;
                    $grew = true;
                }
            }
        } while ($grew);

        $vehicles = [];
        $drivers = [];

        foreach ($tags as $tag) {
            if (! isset($selected[$tag['id']])) {
                continue;
            }

            foreach ($tag['vehicle_ids'] as $id) {
                $vehicles[$id] = true;
            }

            foreach ($tag['driver_ids'] as $id) {
                $drivers[$id] = true;
            }
        }

        return [$vehicles, $drivers];
    }
}
