<?php

namespace App\Http\Controllers\Tenancy;

use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Domains\Tenancy\Support\CostPlusPricing;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant-facing billing page (Roadmap B1b+F7): current plan, features,
 * metered usage for the running period and invoice snapshots. Read-only —
 * payment is by bank transfer and the super-admin manages activation.
 */
class BillingPageController extends Controller
{
    public function show(Team $current_team): Response
    {
        $this->authorize('viewAny', Subscription::class);

        $subscription = Subscription::query()
            ->where('team_id', $current_team->id)
            ->with('plan')
            ->orderByDesc('id')
            ->first();

        // Meters the plan actually invoices (GenerateInvoiceSnapshotJob only
        // bills meters with a BillingRate). Anything else is tracked for
        // visibility but never charged, so it must not read as overage.
        $rates = $subscription?->plan_id !== null
            ? BillingRate::query()
                ->where('plan_id', $subscription->plan_id)
                ->get()
                ->keyBy('usage_meter_id')
            : collect();

        return Inertia::render('billing/index', [
            // Contact point for billing questions (F1.2): payment is by bank
            // transfer, so the page must offer a human path, not a checkout.
            'supportEmail' => fn (): ?string => config('mail.from.address'),
            'subscription' => function () use ($subscription): ?array {
                if ($subscription === null) {
                    return null;
                }

                return [
                    'planName' => $subscription->plan?->name,
                    'planCode' => $subscription->plan?->code,
                    'basePrice' => $subscription->plan?->base_price !== null
                        ? (float) $subscription->plan->base_price
                        : null,
                    'currency' => $subscription->plan?->currency,
                    'billingCycle' => $subscription->billing_cycle?->value ?? (string) $subscription->billing_cycle,
                    'billingCycleLabel' => $subscription->billing_cycle?->label(),
                    'status' => $subscription->status?->value ?? (string) $subscription->status,
                    'statusLabel' => $subscription->status?->label(),
                    // Calendar dates (Y-m-d): an ISO instant at 00:00 UTC
                    // renders as the previous day in Mexico.
                    'renewsAt' => $subscription->renews_at?->toDateString(),
                    'trialEndsAt' => $subscription->trial_ends_at?->toDateString(),
                ];
            },
            // Module switches only: per-meter allowances also live in
            // tenant_features (keyed by meter code) but belong to the usage
            // table, not to the features list.
            'features' => fn () => TenantFeature::query()
                ->where('team_id', $current_team->id)
                ->whereNotIn('feature_key', UsageMeter::query()->select('code'))
                ->orderBy('feature_key')
                ->get()
                ->map(fn (TenantFeature $feature): array => [
                    'key' => $feature->feature_key,
                    'enabled' => (bool) $feature->enabled,
                    'source' => $feature->source?->value ?? (string) $feature->source,
                    'sourceLabel' => $feature->source?->label(),
                    'limits' => $feature->limits_json,
                ])
                ->all(),
            'usage' => fn () => TenantUsageCounter::query()
                ->where('team_id', $current_team->id)
                ->where('period_end', '>=', now())
                ->with('usageMeter')
                ->orderBy('usage_meter_id')
                ->get()
                ->map(fn (TenantUsageCounter $counter): array => [
                    'meterCode' => $counter->usageMeter?->code,
                    // Invoiced by the plan at all, and whether overage costs.
                    'billed' => $rates->has($counter->usage_meter_id),
                    'overageCharged' => (float) ($rates->get($counter->usage_meter_id)?->overage_unit_price ?? 0) > 0,
                    'meterName' => $counter->usageMeter?->name,
                    'unit' => $counter->usageMeter?->unit,
                    // Cost-plus meters (Twilio messaging) accumulate provider
                    // cost in micro-USD: the tenant sees what it will be
                    // charged, never a raw micro count nor our cost.
                    'amount' => $counter->usageMeter?->unit === CostPlusPricing::MICRO_UNIT
                        ? CostPlusPricing::charged(
                            (float) $counter->consumed_value,
                            CostPlusPricing::markupFor($current_team->id, (int) $counter->usage_meter_id),
                        )
                        : null,
                    'consumed' => (float) $counter->consumed_value,
                    'included' => (float) $counter->included_value,
                    'overage' => (float) $counter->overage_value,
                    'periodStart' => $counter->period_start?->toDateString(),
                    'periodEnd' => $counter->period_end?->toDateString(),
                ])
                ->all(),
            'invoices' => fn () => InvoiceSnapshot::query()
                ->where('team_id', $current_team->id)
                ->orderByDesc('period_start')
                ->limit(24)
                ->get()
                ->map(fn (InvoiceSnapshot $invoice): array => [
                    'id' => (int) $invoice->id,
                    'periodStart' => $invoice->period_start?->toDateString(),
                    'periodEnd' => $invoice->period_end?->toDateString(),
                    'subtotal' => (float) $invoice->subtotal,
                    'overageTotal' => (float) $invoice->overage_total,
                    'total' => (float) $invoice->total,
                    'currency' => $invoice->currency,
                    'status' => $invoice->status?->value ?? (string) $invoice->status,
                    'statusLabel' => $invoice->status?->label(),
                    'awaitsPayment' => $invoice->awaitsPayment(),
                    'paidAt' => $invoice->paid_at?->toIso8601String(),
                    'hasReceipt' => $invoice->payment_receipt_file_object_id !== null,
                    'paymentNote' => $invoice->payment_note,
                    'breakdown' => $invoice->breakdown_json,
                ])
                ->all(),
        ]);
    }
}
