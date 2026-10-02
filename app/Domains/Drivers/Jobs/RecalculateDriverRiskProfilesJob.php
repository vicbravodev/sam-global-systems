<?php

namespace App\Domains\Drivers\Jobs;

use App\Domains\Context\Actions\LoadRecentAssetHistory;
use App\Domains\Drivers\Enums\RiskLevel;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverRiskProfile;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentSupervisors;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Daily driver risk recalculation (Roadmap V2-D1): aggregates the last 30
 * days of safety events and incidents per driver into the until-now static
 * `DriverRiskProfile` (score 0–100, level, counters, trend), and raises a
 * preventive `driver.risk_deteriorated` notification when a driver crosses
 * into high/critical — the accident you can still prevent.
 *
 * Drivers with neither events in the window nor an existing profile are
 * skipped (no point materializing all-zero rows).
 */
class RecalculateDriverRiskProfilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const int WINDOW_DAYS = 30;

    /** @var array<int, string> */
    public const array FATIGUE_CODES = ['driver_fatigue', 'driver_distraction', 'mobile_usage'];

    /** @var array<int, string> */
    public const array SEVERE_CODES = ['collision', 'near_collision', 'severe_speeding', 'ran_red_light', 'rollover_protection'];

    /** Above the supervisor default, below the `redis` retry_after (240 s). */
    public int $timeout = 220;

    public function __construct()
    {
        $this->onQueue('analytics');
    }

    /**
     * Resumen del barrido: conteos por tenant y en total.
     *
     * @var array{tenants: int, drivers_scanned: int, skipped_no_activity: int, recalculated: int, alerts_raised: int}
     */
    private array $summary = ['tenants' => 0, 'drivers_scanned' => 0, 'skipped_no_activity' => 0, 'recalculated' => 0, 'alerts_raised' => 0];

    public function handle(SendNotification $sendNotification): void
    {
        $started = hrtime(true);

        // Recorre todos los tenants a propósito, pero recalcula cada conductor
        // dentro del contexto de SU tenant. Ver §2.1. Los conteos se agregan
        // por lote de conductores (2 queries por tenant y chunk, no 2 por
        // conductor).
        TenantContext::withoutTenant(fn () => Driver::query()
            ->whereNotNull('team_id')
            ->with('riskProfile')
            ->chunkById(200, function ($drivers) use ($sendNotification) {
                foreach ($drivers->groupBy('team_id') as $teamId => $teamDrivers) {
                    $this->summary['tenants']++;
                    TenantContext::for((int) $teamId, function () use ($teamDrivers, $sendNotification) {
                        $since = now()->subDays(self::WINDOW_DAYS);
                        $driverIds = $teamDrivers->modelKeys();

                        $eventCounts = NormalizedEvent::query()
                            ->whereIn('driver_id', $driverIds)
                            ->where('occurred_at', '>=', $since)
                            ->join('event_types', 'event_types.id', '=', 'normalized_events.event_type_id')
                            ->selectRaw('normalized_events.driver_id as driver_id, event_types.code as code, count(*) as total')
                            ->groupBy('normalized_events.driver_id', 'event_types.code')
                            ->get()
                            ->groupBy('driver_id');

                        $incidentCounts = Incident::query()
                            ->whereIn('driver_id', $driverIds)
                            ->where('opened_at', '>=', $since)
                            ->selectRaw('driver_id, count(*) as total')
                            ->groupBy('driver_id')
                            ->pluck('total', 'driver_id');

                        foreach ($teamDrivers as $driver) {
                            $this->summary['drivers_scanned']++;
                            $counts = ($eventCounts->get($driver->id) ?? collect())
                                ->mapWithKeys(fn ($row) => [$row->code => (int) $row->total]);

                            $this->recalculate(
                                $driver,
                                $counts,
                                (int) ($incidentCounts->get($driver->id) ?? 0),
                                $sendNotification,
                            );
                        }
                    });
                }
            }));

        // `tenants` cuenta grupos por lote: un tenant con más de 200
        // conductores suma una vez por lote.
        SystemLog::ok('drivers.risk_sweep.completed', calc: [
            'window_days' => self::WINDOW_DAYS,
        ], result: $this->summary, durationMs: SystemLog::elapsedMs($started));
    }

    /**
     * @param  Collection<string, int>  $counts  event_types.code => events in the window
     */
    private function recalculate(Driver $driver, Collection $counts, int $incidentsCount, SendNotification $sendNotification): void
    {
        if ($counts->isEmpty() && $incidentsCount === 0 && $driver->riskProfile === null) {
            $this->summary['skipped_no_activity']++;

            return;
        }

        $harsh = $this->sumCodes($counts, LoadRecentAssetHistory::HARSH_DRIVING_CODES);
        $fatigue = $this->sumCodes($counts, self::FATIGUE_CODES);
        $severe = $this->sumCodes($counts, self::SEVERE_CODES);
        $other = max(0, (int) $counts->sum() - $harsh - $fatigue - $severe);

        $score = min(100.0, round(
            ($harsh * 4.0) + ($fatigue * 8.0) + ($severe * 15.0) + ($other * 2.0) + ($incidentsCount * 10.0),
            2,
        ));

        $level = $this->levelFor($score);

        $previous = $driver->riskProfile;
        $previousScore = $previous !== null ? (float) $previous->risk_score : null;
        $previousLevel = $previous?->risk_level;

        $trend = match (true) {
            $previousScore === null => 'baseline',
            $score > $previousScore => 'deteriorating',
            $score < $previousScore => 'improving',
            default => 'stable',
        };

        DriverRiskProfile::query()->updateOrCreate(
            ['driver_id' => $driver->id],
            [
                'risk_score' => $score,
                'risk_level' => $level,
                'incidents_count' => $incidentsCount,
                'harsh_events_count' => $harsh,
                'fatigue_flags_count' => $fatigue,
                'last_calculated_at' => now(),
                'metadata_json' => [
                    'window_days' => self::WINDOW_DAYS,
                    'previous_score' => $previousScore,
                    'trend' => $trend,
                    'severe_events_count' => $severe,
                ],
            ],
        );

        $this->summary['recalculated']++;

        $alerted = $this->notifyOnDeterioration($driver, $score, $level, $previousScore, $previousLevel, $sendNotification);

        if ($alerted) {
            $this->summary['alerts_raised']++;
        }

        // El score se rehace a mano: cada término con su peso. Una línea por
        // conductor y día: a debug salvo que el nivel cambie.
        SystemLog::ok('drivers.risk_profile.recalculated', input: [
            'team_id' => $driver->team_id,
            'driver_id' => $driver->id,
        ], calc: [
            'window_days' => self::WINDOW_DAYS,
            'harsh_events' => $harsh,
            'fatigue_events' => $fatigue,
            'severe_events' => $severe,
            'other_events' => $other,
            'incidents' => $incidentsCount,
            'weights' => ['harsh' => 4.0, 'fatigue' => 8.0, 'severe' => 15.0, 'other' => 2.0, 'incident' => 10.0],
            'cap' => 100.0,
            'level_thresholds' => ['low' => 25.0, 'medium' => 50.0, 'high' => 75.0],
            'previous_score' => $previousScore,
        ], result: [
            'risk_score' => $score,
            'risk_level' => $level->value,
            'previous_level' => $previousLevel?->value,
            'trend' => $trend,
            'alert_raised' => $alerted,
        ], debug: $previousLevel === $level);
    }

    /**
     * Alert only when the driver CROSSES into high/critical (not while
     * staying there): the operations team gets one heads-up per degradation,
     * idempotent per driver per day. Devuelve si pidió el aviso.
     */
    private function notifyOnDeterioration(
        Driver $driver,
        float $score,
        RiskLevel $level,
        ?float $previousScore,
        ?RiskLevel $previousLevel,
        SendNotification $sendNotification,
    ): bool {
        if (! in_array($level, [RiskLevel::High, RiskLevel::Critical], true)) {
            return false;
        }

        $wasAlreadyThere = in_array($previousLevel, [RiskLevel::High, RiskLevel::Critical], true)
            && $previousScore !== null
            && $score <= $previousScore;

        if ($wasAlreadyThere) {
            return false;
        }

        $sendNotification->execute(
            teamId: $driver->team_id,
            notificationType: 'driver.risk_deteriorated',
            sourceType: NotificationSourceType::SystemEvent,
            sourceReferenceId: (string) $driver->id,
            priority: NotificationPriority::High,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: sprintf('driver_risk_deteriorated:%d:%s', $driver->id, now()->toDateString()),
            // A quien opera la flota (supervisores/admins), en la app y por
            // correo: es un aviso de seguimiento, no una emergencia. Antes iba
            // al equipo entero, uno por conductor y día.
            payload: [
                ...$this->operationsAudience($driver->team_id),
                'force_channels' => [ChannelType::Web->value, ChannelType::Email->value],
                'driver_id' => $driver->id,
                'driver_name' => $driver->full_name ?? trim(($driver->first_name ?? '').' '.($driver->last_name ?? '')),
                'risk_score' => $score,
                'risk_level' => $level->value,
                'previous_score' => $previousScore,
            ],
            subject: 'Riesgo de conductor en deterioro',
            bodyPreview: sprintf(
                'El conductor %s alcanzó riesgo %s (%.0f/100) por sus safety events de los últimos %d días.',
                $driver->full_name ?? "#{$driver->id}",
                $level->value,
                $score,
                self::WINDOW_DAYS,
            ),
        );

        return true;
    }

    /**
     * @return array{recipients?: array<int, array<string, mixed>>}
     */
    private function operationsAudience(int $teamId): array
    {
        $recipients = IncidentSupervisors::recipients($teamId);

        return $recipients !== [] ? ['recipients' => $recipients] : [];
    }

    /**
     * @param  Collection<string, mixed>  $counts
     * @param  array<int, string>  $codes
     */
    private function sumCodes($counts, array $codes): int
    {
        return collect($codes)->sum(fn (string $code) => (int) ($counts[$code] ?? 0));
    }

    private function levelFor(float $score): RiskLevel
    {
        return match (true) {
            $score <= 25.0 => RiskLevel::Low,
            $score <= 50.0 => RiskLevel::Medium,
            $score <= 75.0 => RiskLevel::High,
            default => RiskLevel::Critical,
        };
    }
}
