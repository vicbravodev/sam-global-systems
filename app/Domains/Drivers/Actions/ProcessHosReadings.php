<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Data\HosEnrollment;
use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Drivers\Support\HosSituationDetector;
use App\Domains\Integrations\Data\HosClockReading;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Applies one successful HOS poll of a tenant: stores each monitored
 * driver's clocks, opens and resolves HOS episodes, and closes the episodes
 * of drivers that left the monitored set. PR 1 only observes — nothing is
 * sent to anyone; the episodes are the log the reminder ladder will act on.
 *
 * Must only be called with a COMPLETE poll: an empty enrollment closes every
 * open episode as `unenrolled`.
 */
class ProcessHosReadings
{
    public function __construct(private readonly HosSituationDetector $detector) {}

    /**
     * @return array{monitored: int, opened: int, resolved: int, unenrolled: int, app_disconnected: int}
     */
    public function execute(int $teamId, HosMonitoringConfig $config, HosEnrollment $enrollment, CarbonInterface $now): array
    {
        return TenantContext::for($teamId, function () use ($teamId, $config, $enrollment, $now): array {
            $counts = ['monitored' => count($enrollment->enrolled), 'opened' => 0, 'resolved' => 0, 'unenrolled' => 0, 'app_disconnected' => 0];

            $driverIds = array_map(fn (array $row) => $row['driver']->id, $enrollment->enrolled);

            $states = HosDriverState::query()
                ->where('team_id', $teamId)
                ->whereIn('driver_id', $driverIds)
                ->get()
                ->keyBy('driver_id');

            $openEpisodes = HosEpisode::query()
                ->where('team_id', $teamId)
                ->open()
                ->get()
                ->groupBy('driver_id');

            foreach ($enrollment->enrolled as ['reading' => $reading, 'driver' => $driver, 'asset' => $asset]) {
                $state = $states->get($driver->id);
                $open = $openEpisodes->get($driver->id, collect())->keyBy(fn (HosEpisode $e) => $e->situation->value);

                $detection = $this->detector->detect(
                    $state?->toReading($reading->externalDriverId, $reading->externalVehicleId),
                    $reading,
                    $config,
                    $open->map(fn (HosEpisode $e) => $e->opened_at)->all(),
                    $now,
                );

                foreach ($detection->resolve as $situation => $resolution) {
                    $this->resolve($open->get($situation), $resolution, $now, $reading);
                    $counts['resolved']++;
                }

                foreach ($detection->open as $situation) {
                    if ($this->open($teamId, $driver->id, $asset->id, $situation, $reading, $config, $now)) {
                        $counts['opened']++;
                    }
                }

                if ($this->storeState($teamId, $driver->id, $asset->id, $state, $reading, $now)) {
                    $counts['app_disconnected']++;
                }
            }

            $enrolledIds = array_flip($driverIds);

            foreach ($openEpisodes as $driverId => $episodes) {
                if (isset($enrolledIds[$driverId])) {
                    continue;
                }

                foreach ($episodes as $episode) {
                    $this->resolve($episode, HosEpisodeResolution::Unenrolled, $now, null);
                    $counts['unenrolled']++;
                }
            }

            return $counts;
        });
    }

    private function open(int $teamId, int $driverId, int $assetId, HosSituation $situation, HosClockReading $reading, HosMonitoringConfig $config, CarbonInterface $now): bool
    {
        try {
            $episode = HosEpisode::query()->create([
                'team_id' => $teamId,
                'driver_id' => $driverId,
                'asset_id' => $assetId,
                'situation' => $situation,
                'opened_at' => $now,
                'snapshot_json' => $reading->toArray(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otro sondeo solapado ya lo abrió: el índice parcial es la defensa.
            SystemLog::skipped('hos.episode.opened', reason: 'already_open', input: [
                'team_id' => $teamId,
                'driver_id' => $driverId,
            ], calc: ['situation' => $situation->value]);

            return false;
        }

        SystemLog::ok('hos.episode.opened', input: [
            'team_id' => $teamId,
            'driver_id' => $driverId,
            'asset_id' => $assetId,
        ], calc: [
            'situation' => $situation->value,
            'duty_status' => $reading->dutyStatus,
            'break_remaining_s' => $reading->breakRemainingSeconds,
            'drive_remaining_s' => $reading->driveRemainingSeconds,
            'shift_remaining_s' => $reading->shiftRemainingSeconds,
            'cycle_remaining_s' => $reading->cycleRemainingSeconds,
            'violation_s' => $reading->violationSeconds,
            'lead_s' => $config->leadSeconds(),
            'cycle_lead_s' => $config->cycleLeadSeconds(),
        ], result: ['episode_id' => $episode->id]);

        return true;
    }

    private function resolve(HosEpisode $episode, HosEpisodeResolution $resolution, CarbonInterface $now, ?HosClockReading $reading): void
    {
        $episode->forceFill(['resolved_at' => $now, 'resolution' => $resolution])->save();

        SystemLog::ok('hos.episode.resolved', input: [
            'team_id' => $episode->team_id,
            'driver_id' => $episode->driver_id,
            'episode_id' => $episode->id,
        ], calc: [
            'situation' => $episode->situation->value,
            'duty_status' => $reading?->dutyStatus,
            'open_seconds' => (int) $episode->opened_at->diffInSeconds($now),
        ], result: ['resolution' => $resolution->value]);
    }

    /**
     * @return bool whether the driver app just went dark (first poll without status)
     */
    private function storeState(int $teamId, int $driverId, int $assetId, ?HosDriverState $state, HosClockReading $reading, CarbonInterface $now): bool
    {
        $status = HosDutyStatus::tryFrom((string) $reading->dutyStatus);

        if ($status === null) {
            // Estado desconocido: se conservan los últimos relojes conocidos.
            $state ??= new HosDriverState(['team_id' => $teamId, 'driver_id' => $driverId]);
            $justDisconnected = $state->app_disconnected_since === null;
            $state->forceFill([
                'asset_id' => $assetId,
                'app_disconnected_since' => $state->app_disconnected_since ?? $now,
                'observed_at' => $now,
            ])->save();

            if ($justDisconnected) {
                SystemLog::degraded('hos.driver.app_disconnected', reason: 'empty_duty_status', input: [
                    'team_id' => $teamId,
                    'driver_id' => $driverId,
                    'asset_id' => $assetId,
                ]);
            }

            return $justDisconnected;
        }

        $state ??= new HosDriverState(['team_id' => $teamId, 'driver_id' => $driverId]);

        $state->forceFill([
            'asset_id' => $assetId,
            'duty_status' => $status,
            'status_since' => $state->duty_status === $status ? $state->status_since : $now,
            'break_remaining_s' => $reading->breakRemainingSeconds,
            'drive_remaining_s' => $reading->driveRemainingSeconds,
            'shift_remaining_s' => $reading->shiftRemainingSeconds,
            'cycle_remaining_s' => $reading->cycleRemainingSeconds,
            'violation_s' => $reading->violationSeconds,
            'app_disconnected_since' => null,
            'observed_at' => $now,
        ])->save();

        return false;
    }
}
