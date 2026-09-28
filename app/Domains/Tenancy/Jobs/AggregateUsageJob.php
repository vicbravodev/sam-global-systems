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

        if ($this->teamId) {
            $teamsQuery->where('id', $this->teamId);
        }

        $meters = UsageMeter::all();

        $teamsQuery->chunkById(100, function ($teams) use ($meters) {
            foreach ($teams as $team) {
                foreach ($meters as $meter) {
                    $this->aggregateForTeamMeter($team, $meter);
                }
            }
        });
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

    private function aggregateForTeamMeter(Team $team, UsageMeter $meter): void
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

        foreach ($dailyData as $row) {
            UsageDailyAggregate::query()->upsert(
                [
                    'team_id' => $team->id,
                    'usage_meter_id' => $meter->id,
                    'day' => $row->day,
                    'quantity_sum' => $row->quantity_sum,
                    'quantity_max' => $row->quantity_max,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                ['team_id', 'usage_meter_id', 'day'],
                ['quantity_sum', 'quantity_max', 'updated_at'],
            );
        }

        $this->recalculateCounter($team, $meter, $periodStart, $periodEnd);
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

    private function recalculateCounter(Team $team, UsageMeter $meter, CarbonInterface $periodStart, CarbonInterface $periodEnd): void
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
        if ($this->isClosedPeriod()) {
            return;
        }

        if ($overageValue > 0 && $previousOverage === 0) {
            UsageLimitExceeded::dispatch(
                $team->id,
                $meter->code,
                (int) $totalConsumed,
                $includedValue,
            );
        }

        $this->broadcastIfSignificantChange($team, $meter, $totalConsumed, $includedValue, $overageValue, $previousCounter, $periodStart, $periodEnd);
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
    ): void {
        if (! $previousCounter) {
            return;
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
        }
    }
}
