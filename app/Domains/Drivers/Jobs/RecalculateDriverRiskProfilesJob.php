<?php

namespace App\Domains\Drivers\Jobs;

use App\Domains\Context\Actions\LoadRecentAssetHistory;
use App\Domains\Drivers\Enums\RiskLevel;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverRiskProfile;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
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

    public function __construct()
    {
        $this->onQueue('analytics');
    }

    public function handle(SendNotification $sendNotification): void
    {
        Driver::withoutGlobalScopes()
            ->whereNotNull('team_id')
            ->with('riskProfile')
            ->chunkById(200, function ($drivers) use ($sendNotification) {
                foreach ($drivers as $driver) {
                    $this->recalculate($driver, $sendNotification);
                }
            });
    }

    private function recalculate(Driver $driver, SendNotification $sendNotification): void
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        $counts = NormalizedEvent::withoutGlobalScopes()
            ->where('driver_id', $driver->id)
            ->where('occurred_at', '>=', $since)
            ->join('event_types', 'event_types.id', '=', 'normalized_events.event_type_id')
            ->selectRaw('event_types.code as code, count(*) as total')
            ->groupBy('event_types.code')
            ->pluck('total', 'code');

        $incidentsCount = Incident::withoutGlobalScopes()
            ->where('driver_id', $driver->id)
            ->where('opened_at', '>=', $since)
            ->count();

        if ($counts->isEmpty() && $incidentsCount === 0 && $driver->riskProfile === null) {
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

        $this->notifyOnDeterioration($driver, $score, $level, $previousScore, $previousLevel, $sendNotification);
    }

    /**
     * Alert only when the driver CROSSES into high/critical (not while
     * staying there): the operations team gets one heads-up per degradation,
     * idempotent per driver per day.
     */
    private function notifyOnDeterioration(
        Driver $driver,
        float $score,
        RiskLevel $level,
        ?float $previousScore,
        ?RiskLevel $previousLevel,
        SendNotification $sendNotification,
    ): void {
        if (! in_array($level, [RiskLevel::High, RiskLevel::Critical], true)) {
            return;
        }

        $wasAlreadyThere = in_array($previousLevel, [RiskLevel::High, RiskLevel::Critical], true)
            && $previousScore !== null
            && $score <= $previousScore;

        if ($wasAlreadyThere) {
            return;
        }

        $sendNotification->execute(
            teamId: (int) $driver->team_id,
            notificationType: 'driver.risk_deteriorated',
            sourceType: NotificationSourceType::SystemEvent,
            sourceReferenceId: (string) $driver->id,
            priority: NotificationPriority::High,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: sprintf('driver_risk_deteriorated:%d:%s', $driver->id, now()->toDateString()),
            payload: [
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
    }

    /**
     * @param  Collection<string, mixed>  $counts
     * @param  array<int, string>  $codes
     */
    private function sumCodes($counts, array $codes): int
    {
        return (int) collect($codes)->sum(fn (string $code) => (int) ($counts[$code] ?? 0));
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
