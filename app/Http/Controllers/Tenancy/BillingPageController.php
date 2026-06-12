<?php

namespace App\Http\Controllers\Tenancy;

use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\Tenancy\Models\TenantUsageCounter;
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

        return Inertia::render('billing/index', [
            // Contact point for billing questions (F1.2): payment is by bank
            // transfer, so the page must offer a human path, not a checkout.
            'supportEmail' => fn (): ?string => config('mail.from.address'),
            'subscription' => function () use ($current_team): ?array {
                $subscription = Subscription::withoutGlobalScopes()
                    ->where('team_id', $current_team->id)
                    ->with('plan')
                    ->orderByDesc('id')
                    ->first();

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
                    'status' => $subscription->status?->value ?? (string) $subscription->status,
                    'renewsAt' => $subscription->renews_at?->toIso8601String(),
                    'trialEndsAt' => $subscription->trial_ends_at?->toIso8601String(),
                ];
            },
            'features' => fn () => TenantFeature::withoutGlobalScopes()
                ->where('team_id', $current_team->id)
                ->orderBy('feature_key')
                ->get()
                ->map(fn (TenantFeature $feature): array => [
                    'key' => $feature->feature_key,
                    'enabled' => (bool) $feature->enabled,
                    'source' => $feature->source?->value ?? (string) $feature->source,
                    'limits' => $feature->limits_json,
                ])
                ->all(),
            'usage' => fn () => TenantUsageCounter::withoutGlobalScopes()
                ->where('team_id', $current_team->id)
                ->where('period_end', '>=', now())
                ->with('usageMeter')
                ->orderBy('usage_meter_id')
                ->get()
                ->map(fn (TenantUsageCounter $counter): array => [
                    'meterCode' => $counter->usageMeter?->code,
                    'meterName' => $counter->usageMeter?->name,
                    'unit' => $counter->usageMeter?->unit,
                    'consumed' => (float) $counter->consumed_value,
                    'included' => (float) $counter->included_value,
                    'overage' => (float) $counter->overage_value,
                    'periodStart' => $counter->period_start?->toIso8601String(),
                    'periodEnd' => $counter->period_end?->toIso8601String(),
                ])
                ->all(),
            'invoices' => fn () => InvoiceSnapshot::withoutGlobalScopes()
                ->where('team_id', $current_team->id)
                ->orderByDesc('period_start')
                ->limit(24)
                ->get()
                ->map(fn (InvoiceSnapshot $invoice): array => [
                    'id' => (int) $invoice->id,
                    'periodStart' => $invoice->period_start?->toIso8601String(),
                    'periodEnd' => $invoice->period_end?->toIso8601String(),
                    'subtotal' => (float) $invoice->subtotal,
                    'overageTotal' => (float) $invoice->overage_total,
                    'total' => (float) $invoice->total,
                    'currency' => $invoice->currency,
                    'status' => $invoice->status?->value ?? (string) $invoice->status,
                    'paidAt' => $invoice->paid_at?->toIso8601String(),
                    'hasReceipt' => $invoice->payment_receipt_file_object_id !== null,
                    'paymentNote' => $invoice->payment_note,
                    'breakdown' => $invoice->breakdown_json,
                ])
                ->all(),
        ]);
    }
}
