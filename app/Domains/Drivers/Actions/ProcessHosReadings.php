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
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies one successful HOS poll of a tenant: stores each monitored
 * driver's clocks, opens and resolves HOS episodes, closes the episodes of
 * drivers that left the monitored set and, when the driver corrects an
 * episode that already escalated, settles its incident
 * ({@see SettleHosIncident}). The reminders are sent right after, by
 * {@see AdvanceHosEpisodes}.
 *
 * Must only be called with a COMPLETE poll: an empty enrollment closes every
 * open episode as `unenrolled`.
 */
class ProcessHosReadings
{
    public function __construct(
        private readonly HosSituationDetector $detector,
        private readonly SettleHosIncident $settleIncident,
    ) {}

    /**
     * @return array{monitored: int, opened: int, resolved: int, unenrolled: int, app_disconnected: int}
     */
    public function execute(int $teamId, HosMonitoringConfig $config, HosEnrollment $enrollment, CarbonInterface $now): array
    {
        return TenantContext::for($teamId, function () use ($teamId, $config, $enrollment, $now): array {
            // Un chofer por llamada: la primera fila gana.
            $enrolled = [];
            foreach ($enrollment->enrolled as $row) {
                $enrolled[$row['driver']->id] ??= $row;
            }
            $enrolled = array_values($enrolled);

            $counts = ['monitored' => count($enrolled), 'opened' => 0, 'resolved' => 0, 'unenrolled' => 0, 'app_disconnected' => 0];

            $driverIds = array_map(fn (array $row) => $row['driver']->id, $enrolled);

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

            foreach ($enrolled as ['reading' => $reading, 'driver' => $driver, 'asset' => $asset]) {
                $state = $states->get($driver->id);
                $open = $openEpisodes->get($driver->id, collect())->keyBy(fn (HosEpisode $e) => $e->situation->value);

                $previous = $this->previousReading($state, $reading, $config, $now);

                $detection = $this->detector->detect(
                    $previous,
                    $reading,
                    $config,
                    $open->map(fn (HosEpisode $e) => $e->opened_at)->all(),
                    $now,
                );

                /** @var list<HosEpisode> $toSettle */
                $toSettle = [];

                // Atómico por chofer: o se aplica todo su sondeo o nada.
                $delta = DB::transaction(function () use ($detection, $open, $reading, $teamId, $driver, $asset, $config, $now, $state, &$toSettle): array {
                    $d = ['opened' => 0, 'resolved' => 0, 'app_disconnected' => 0];

                    foreach ($detection->resolve as $situation => $resolution) {
                        $episode = $open->get($situation);

                        if ($episode === null) {
                            continue;
                        }

                        $this->resolve($episode, $resolution, $now, $reading);
                        $d['resolved']++;

                        // Ya había escalado: su incidente se atiende fuera de la transacción.
                        if ($resolution === HosEpisodeResolution::Corrected && $episode->escalated_at !== null) {
                            $toSettle[] = $episode;
                        }
                    }

                    foreach ($detection->open as $situation) {
                        if ($this->open($teamId, $driver->id, $asset->id, $situation, $reading, $config, $now)) {
                            $d['opened']++;
                        }
                    }

                    if ($this->storeState($teamId, $driver->id, $asset->id, $state, $reading, $now)) {
                        $d['app_disconnected']++;
                    }

                    return $d;
                });

                foreach ($delta as $key => $n) {
                    $counts[$key] += $n;
                }

                // Tras el commit del chofer: un fallo aquí no revierte su sondeo
                // ni el de los demás, y los eventos del cierre salen ya confirmados.
                foreach ($toSettle as $episode) {
                    $this->settle($episode);
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

    /**
     * The stored clocks are only a usable "before" of a transition while
     * they are recent. Up to the rest-complete window (default 35 min) the
     * last real reading is trustworthy: a break served during a short
     * Samsara outage must not be missed. Past it, the natural reset of the
     * clocks after hours away would read as a pause just served (false
     * rest_complete). A disconnected app keeps refreshing `observed_at` with
     * frozen clocks, so its age counts from `app_disconnected_since`, the
     * last moment the clocks were real.
     */
    private function previousReading(?HosDriverState $state, HosClockReading $reading, HosMonitoringConfig $config, CarbonInterface $now): ?HosClockReading
    {
        if ($state === null) {
            return null;
        }

        $lastRealAt = $state->app_disconnected_since ?? $state->observed_at;

        if ($lastRealAt->lt($now->toImmutable()->subSeconds($config->restCompleteExpireSeconds()))) {
            return null;
        }

        return $state->toReading($reading->externalDriverId, $reading->externalVehicleId);
    }

    private function settle(HosEpisode $episode): void
    {
        try {
            $this->settleIncident->execute($episode);
        } catch (Throwable $e) {
            // Un incidente que no se pudo cerrar no tumba el sondeo: sigue en la bandeja.
            SystemLog::failed('hos.incident.settled', reason: 'exception', input: [
                'team_id' => $episode->team_id,
                'episode_id' => $episode->id,
                'driver_id' => $episode->driver_id,
            ], error: $e);
        }
    }

    private function open(int $teamId, int $driverId, int $assetId, HosSituation $situation, HosClockReading $reading, HosMonitoringConfig $config, CarbonInterface $now): bool
    {
        try {
            // Savepoint: en PostgreSQL un error de unicidad aborta la transacción
            // entera si no se aísla.
            $episode = DB::transaction(fn () => HosEpisode::query()->create([
                'team_id' => $teamId,
                'driver_id' => $driverId,
                'asset_id' => $assetId,
                'situation' => $situation,
                'opened_at' => $now,
                'snapshot_json' => $reading->toArray(),
            ]));
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
