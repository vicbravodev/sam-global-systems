<?php

namespace App\Domains\Tenancy\Jobs;

use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Events\UsageLimitExceeded;
use App\Domains\Tenancy\Events\UsageUpdatedBroadcast;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageDailyAggregate;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

class AggregateUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Above the supervisor default, below the `redis` retry_after (240 s). */
    public int $timeout = 220;

    public array $backoff = [1, 5, 10];

    /**
     * @param  string|null  $forMonth  Any date inside the billing month to
     *                                 (re)compute. Null = the current month. A past month is how the
     *                                 invoicing run closes the previous period before invoicing it.
     */
    public function __construct(
        public ?int $teamId = null,
        public ?string $forMonth = null,
    ) {
        $this->onQueue('billing');
    }

    public function handle(): void
    {
        $teamsQuery = Team::query()
            ->whereHas('teamSubscription', function ($query) {
                $query->withoutGlobalScopes()
                    ->whereIn('status', [
                        SubscriptionStatus::Active->value,
                        SubscriptionStatus::PastDue->value,
                    ]);
            });

        if ($this->teamId === null) {
            // Scheduled run (no team): fan out one job per tenant, like the
            // monthly invoicing. One job for every tenant grows past its timeout
            // with the customer base, and a retry restarted everyone from zero.
            $dispatched = 0;

            $teamsQuery->select('teams.id')->chunkById(100, function ($teams) use (&$dispatched) {
                foreach ($teams as $team) {
                    self::dispatch((int) $team->id, $this->forMonth);
                    $dispatched++;
                }
            });

            // Recorrido de plataforma: solo el conteo, nunca ids de tenants.
            SystemLog::ok('billing.aggregate.fanned_out',
                calc: ['for_month' => $this->forMonth, 'period_start' => $this->periodStart()->toDateString()],
                result: ['jobs_dispatched_count' => $dispatched],
            );

            return;
        }

        $teamId = $this->teamId;
        $team = $teamsQuery->whereKey($teamId)->first();

        if ($team === null) {
            TenantContext::for($teamId, fn () => SystemLog::skipped('billing.aggregate.completed',
                reason: 'no_operational_subscription',
                input: ['team_id' => $teamId, 'period_start' => $this->periodStart()->toDateString()],
            ));

            return;
        }

        $totals = ['meters_count' => 0, 'meters_with_overage_count' => 0, 'daily_rows_upserted_count' => 0,
            'limit_events_dispatched_count' => 0, 'broadcasts_dispatched_count' => 0];

        foreach (UsageMeter::all() as $meter) {
            $outcome = $this->aggregateForTeamMeter($team, $meter);

            $totals['meters_count']++;
            $totals['meters_with_overage_count'] += $outcome['overage'] > 0 ? 1 : 0;
            $totals['daily_rows_upserted_count'] += $outcome['daily_rows'];
            $totals['limit_events_dispatched_count'] += $outcome['limit_event'] ? 1 : 0;
            $totals['broadcasts_dispatched_count'] += $outcome['broadcast'] ? 1 : 0;
        }

        TenantContext::for($team->id, fn () => SystemLog::ok('billing.aggregate.completed',
            input: ['team_id' => $team->id, 'period_start' => $this->periodStart()->toDateString()],
            calc: ['closed_period' => $this->isClosedPeriod()],
            result: $totals,
        ));
    }

    private function periodStart(): CarbonInterface
    {
        $reference = $this->forMonth !== null ? Date::parse($this->forMonth) : now();

        return $reference->copy()->startOfMonth();
    }

    private function isClosedPeriod(): bool
    {
        return $this->periodStart()->lt(now()->startOfMonth());
    }

    /**
     * @return array{daily_rows: int, overage: int, limit_event: bool, broadcast: bool}
     */
    private function aggregateForTeamMeter(Team $team, UsageMeter $meter): array
    {
        $periodStart = $this->periodStart();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $dailyData = UsageEvent::query()
            ->where('team_id', $team->id)
            ->where('usage_meter_id', $meter->id)
            ->where('occurred_at', '>=', $periodStart)
            ->where('occurred_at', '<=', $periodEnd)
            ->select([
                DB::raw('DATE(occurred_at) as day'),
                DB::raw('SUM(quantity) as quantity_sum'),
                DB::raw('MAX(quantity) as quantity_max'),
            ])
            ->groupBy(DB::raw('DATE(occurred_at)'))
            ->get();

        // One upsert for the whole month instead of one per day.
        if ($dailyData->isNotEmpty()) {
            UsageDailyAggregate::query()->upsert(
                $dailyData->map(fn ($row) => [
                    'team_id' => $team->id,
                    'usage_meter_id' => $meter->id,
                    'day' => $row->day,
                    'quantity_sum' => $row->quantity_sum,
                    'quantity_max' => $row->quantity_max,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all(),
                ['team_id', 'usage_meter_id', 'day'],
                ['quantity_sum', 'quantity_max', 'updated_at'],
            );
        }

        return ['daily_rows' => $dailyData->count()]
            + $this->recalculateCounter($team, $meter, $periodStart, $periodEnd);
    }

    /**
     * Period consumption honouring the meter's aggregation type: a Sum meter
     * accumulates (messages, AI calls), a Max meter is a gauge sampled daily
     * (monitored assets, cameras) whose billable value is the peak, never the
     * sum of the daily samples.
     */
    private function periodConsumption(Team $team, UsageMeter $meter, CarbonInterface $periodStart, CarbonInterface $periodEnd): int
    {
        $query = UsageEvent::query()
            ->where('team_id', $team->id)
            ->where('usage_meter_id', $meter->id)
            ->where('occurred_at', '>=', $periodStart)
            ->where('occurred_at', '<=', $periodEnd);

        return (int) match ($meter->aggregation_type) {
            AggregationType::Max => $query->max('quantity') ?? 0,
            AggregationType::Sum, AggregationType::UniqueCount => $query->sum('quantity'),
        };
    }

    /**
     * @return array{overage: int, limit_event: bool, broadcast: bool}
     */
    private function recalculateCounter(Team $team, UsageMeter $meter, CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $totalConsumed = $this->periodConsumption($team, $meter, $periodStart, $periodEnd);

        $subscription = Subscription::query()
            ->where('team_id', $team->id)
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->latest('starts_at')
            ->first();

        $includedValue = 0;
        $billingRate = null;
        if ($subscription) {
            $billingRate = BillingRate::where('plan_id', $subscription->plan_id)
                ->where('usage_meter_id', $meter->id)
                ->first();

            $includedValue = $billingRate?->included_quantity ?? 0;
        }

        $overageValue = max(0, $totalConsumed - $includedValue);

        $previousCounter = TenantUsageCounter::query()
            ->where('team_id', $team->id)
            ->where('usage_meter_id', $meter->id)
            ->whereDate('period_start', $periodStart->toDateString())
            ->first();

        $previousOverage = $previousCounter?->overage_value ?? 0;

        TenantUsageCounter::query()->upsert(
            [
                'team_id' => $team->id,
                'usage_meter_id' => $meter->id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'consumed_value' => $totalConsumed,
                'included_value' => $includedValue,
                'overage_value' => $overageValue,
                'last_calculated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            ['team_id', 'usage_meter_id', 'period_start'],
            ['consumed_value', 'included_value', 'overage_value', 'last_calculated_at', 'updated_at'],
        );

        // Live alerts belong to the running month; closing a past period for
        // invoicing must not re-announce limits or push realtime updates.
        $firstCrossing = ! $this->isClosedPeriod() && $overageValue > 0 && $previousOverage === 0;
        $broadcast = false;

        if (! $this->isClosedPeriod()) {
            if ($firstCrossing) {
                UsageLimitExceeded::dispatch(
                    $team->id,
                    $meter->code,
                    (int) $totalConsumed,
                    $includedValue,
                );
            }

            $broadcast = $this->broadcastIfSignificantChange($team, $meter, $totalConsumed, $includedValue, $overageValue, $previousCounter, $periodStart, $periodEnd);
        }

        TenantContext::for($team->id, fn () => SystemLog::ok('billing.overage.computed',
            input: ['team_id' => $team->id, 'meter_code' => $meter->code, 'period_start' => $periodStart->toDateString()],
            calc: [
                'aggregation_type' => $meter->aggregation_type->value,
                'consumed' => $totalConsumed,
                'included' => (int) $includedValue,
                'included_source' => $subscription === null ? 'no_subscription' : ($billingRate === null ? 'no_plan_rate' : 'plan_rate'),
                'previous_overage' => $previousOverage,
                'previous_counter_found' => $previousCounter !== null,
                'closed_period' => $this->isClosedPeriod(),
                'first_crossing' => $firstCrossing,
                'formula' => 'overage = max(0, consumed - included); first_crossing = !closed_period && overage > 0 && previous_overage == 0',
            ],
            result: ['overage' => $overageValue, 'limit_event_dispatched' => $firstCrossing, 'broadcast_dispatched' => $broadcast],
            debug: $overageValue === 0 && ! $firstCrossing,
        ));

        return ['overage' => $overageValue, 'limit_event' => $firstCrossing, 'broadcast' => $broadcast];
    }

    private function broadcastIfSignificantChange(
        Team $team,
        UsageMeter $meter,
        int $totalConsumed,
        int $includedValue,
        int $overageValue,
        ?TenantUsageCounter $previousCounter,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): bool {
        if (! $previousCounter) {
            return false;
        }

        $previousConsumed = $previousCounter->consumed_value;
        $percentChange = $previousConsumed > 0
            ? abs($totalConsumed - $previousConsumed) / $previousConsumed * 100
            : ($totalConsumed > 0 ? 100 : 0);

        $crossedOverageThreshold = $overageValue > 0 && $previousCounter->overage_value === 0;

        if ($percentChange > 5 || $crossedOverageThreshold) {
            UsageUpdatedBroadcast::dispatch(
                $team->id,
                $meter->code,
                (int) $totalConsumed,
                $includedValue,
                $overageValue,
                $periodStart->toDateString(),
                $periodEnd->toDateString(),
            );

            return true;
        }

        return false;
    }
}
