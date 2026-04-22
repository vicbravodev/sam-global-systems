<?php

namespace App\Domains\Tenancy\Jobs;

use App\Domains\Tenancy\Enums\InvoiceStatus;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateInvoiceSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [1, 5, 10];

    public function __construct(
        public int $teamId,
        public ?string $periodStart = null,
        public ?string $periodEnd = null,
    ) {
        $this->onQueue('billing');
    }

    public function handle(): void
    {
        $team = Team::findOrFail($this->teamId);
        $periodStart = $this->periodStart ?? now()->startOfMonth()->toDateString();
        $periodEnd = $this->periodEnd ?? now()->endOfMonth()->toDateString();

        $existingSnapshot = InvoiceSnapshot::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereDate('period_start', $periodStart)
            ->whereDate('period_end', $periodEnd)
            ->first();

        if ($existingSnapshot) {
            return;
        }

        $subscription = Subscription::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereIn('status', ['trialing', 'active', 'past_due'])
            ->latest('starts_at')
            ->first();

        $basePriceDecimal = $subscription?->plan?->base_price ?? 0;
        $subtotal = (float) $basePriceDecimal;

        $breakdown = [];
        $overageTotal = 0.0;

        if ($subscription) {
            $billingRates = BillingRate::where('plan_id', $subscription->plan_id)
                ->with('usageMeter')
                ->get();

            foreach ($billingRates as $rate) {
                $counter = TenantUsageCounter::withoutGlobalScopes()
                    ->where('team_id', $team->id)
                    ->where('usage_meter_id', $rate->usage_meter_id)
                    ->whereDate('period_start', $periodStart)
                    ->first();

                $consumed = $counter?->consumed_value ?? 0;
                $included = $rate->included_quantity;
                $overage = max(0, $consumed - $included);
                $overageCost = $overage * (float) $rate->overage_unit_price;
                $overageTotal += $overageCost;

                $breakdown[] = [
                    'meter_code' => $rate->usageMeter->code,
                    'meter_name' => $rate->usageMeter->name,
                    'consumed' => $consumed,
                    'included' => $included,
                    'overage' => $overage,
                    'overage_unit_price' => (float) $rate->overage_unit_price,
                    'overage_cost' => $overageCost,
                ];
            }
        }

        $total = $subtotal + $overageTotal;

        InvoiceSnapshot::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'subscription_id' => $subscription?->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'subtotal' => $subtotal,
            'overage_total' => $overageTotal,
            'total' => $total,
            'currency' => $team->currency ?? 'usd',
            'status' => InvoiceStatus::Draft,
            'breakdown_json' => $breakdown,
            'generated_at' => now(),
        ]);
    }
}
