<?php

namespace App\Http\Controllers\Tenancy;

use App\Domains\Assets\Models\Asset;
use App\Domains\Tenancy\Actions\EstimatePeriodCharges;
use App\Domains\Tenancy\Actions\ResolveAssetLimit;
use App\Domains\Tenancy\Actions\ResolveBillingTerms;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Domains\Tenancy\Support\AssetDayPricing;
use App\Domains\Tenancy\Support\CostPlusPricing;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant-facing billing page: units being watched against the contracted
 * cap, what the running month costs so far and at close (asset-days, AI
 * fair use, messaging), metered usage and invoice snapshots. Read-only —
 * payment is by bank transfer and the super-admin manages activation.
 */
class BillingPageController extends Controller
{
    /**
     * Twilio usage counted per channel; charged through the messaging
     * cost-plus meter (FinalizeMessagingCharge), never on their own.
     */
    private const array MESSAGING_COUNT_METERS = [
        'sms_messages',
        'whatsapp_messages',
        'voice_notification_calls',
        'voice_calls',
        'otp_sms_sent',
    ];

    public function show(
        Team $current_team,
        ResolveBillingTerms $resolveTerms,
        EstimatePeriodCharges $estimate,
    ): Response {
        $this->authorize('viewAny', Subscription::class);

        $subscription = Subscription::query()
            ->where('team_id', $current_team->id)
            ->with('plan')
            ->orderByDesc('id')
            ->first();

        // Meters the invoice actually covers (GenerateInvoiceSnapshotJob):
        // asset-days, AI fair use and the unit cap drive the asset-day
        // charge, cost-plus messaging is billed at cost + margin, and any
        // other meter only when the plan has a BillingRate for it. Anything
        // else is tracked for visibility but never charged, so it must not
        // read as overage.
        $rates = $subscription?->plan_id !== null
            ? BillingRate::query()
                ->where('plan_id', $subscription->plan_id)
                ->get()
                ->keyBy('usage_meter_id')
            : collect();
        $terms = $resolveTerms->execute((int) $current_team->id);
        $assetDayMeters = [
            AssetDayPricing::METER_CODE,
            AssetDayPricing::AI_METER_CODE,
            ResolveAssetLimit::METER_CODE,
        ];
        $isBilled = fn (TenantUsageCounter $counter): bool => $rates->has($counter->usage_meter_id)
            || in_array($counter->usageMeter?->code, $assetDayMeters, true)
            || $counter->usageMeter?->unit === CostPlusPricing::MICRO_UNIT;
        // Only AI calls beyond the fair use and rated overage cost extra; the
        // units over the cap are already inside the asset-day charge.
        $overageCharged = fn (TenantUsageCounter $counter): bool => $counter->usageMeter?->code === AssetDayPricing::AI_METER_CODE
            ? $terms->aiOverageUnitPrice > 0
            : (float) ($rates->get($counter->usage_meter_id)?->overage_unit_price ?? 0) > 0;

        return Inertia::render('billing/index', [
            'terms' => fn (): array => $terms->toArray(),
            'estimate' => fn (): array => $estimate->execute((int) $current_team->id),
            'fleet' => function () use ($current_team): array {
                $byState = Asset::query()
                    ->where('team_id', $current_team->id)
                    ->selectRaw('monitoring_state, COUNT(*) as aggregate')
                    ->groupBy('monitoring_state')
                    ->pluck('aggregate', 'monitoring_state');

                return [
                    'monitored' => (int) ($byState['monitored'] ?? 0),
                    'pending' => (int) ($byState['pending'] ?? 0),
                    'excluded' => (int) ($byState['excluded'] ?? 0),
                ];
            },
            // Contact point for billing questions (F1.2): payment is by bank
            // transfer, so the page must offer a human path, not a checkout.
            'supportEmail' => fn (): ?string => config('mail.from.address'),
            // Platform bank details for the transfer (config/billing.php);
            // null until the CLABE is configured, then the page shows them.
            'transfer' => function (): ?array {
                $clabe = config('billing.transfer.clabe');

                if (! is_string($clabe) || trim($clabe) === '') {
                    return null;
                }

                return [
                    'beneficiary' => config('billing.transfer.beneficiary'),
                    'bank' => config('billing.transfer.bank'),
                    'clabe' => trim($clabe),
                ];
            },
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
                    'billingCycle' => $subscription->billing_cycle->value,
                    'billingCycleLabel' => $subscription->billing_cycle->label(),
                    'status' => $subscription->status->value,
                    'statusLabel' => $subscription->status->label(),
                    // Calendar date (Y-m-d): an ISO instant at 00:00 UTC
                    // renders as the previous day in Mexico.
                    'renewsAt' => $subscription->renews_at?->toDateString(),
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
                    'source' => $feature->source->value,
                    'sourceLabel' => $feature->source->label(),
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
                    'billed' => $isBilled($counter),
                    'overageCharged' => $overageCharged($counter),
                    // Per-channel Twilio counts carry no price of their own:
                    // their real cost lands on the messaging (cost-plus) line.
                    'billedVia' => in_array($counter->usageMeter?->code, self::MESSAGING_COUNT_METERS, true)
                        ? 'messaging'
                        : null,
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
                    'status' => $invoice->status->value,
                    'statusLabel' => $invoice->status->label(),
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
