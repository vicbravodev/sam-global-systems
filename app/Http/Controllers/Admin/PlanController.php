<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Tenancy\Actions\UpdatePlanLimits;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super-admin plan catalog. Exposes each plan's per-meter included quantities
 * (the asset cap among them) so the operator can tune what a plan allows.
 */
class PlanController extends Controller
{
    public function __construct(private readonly RecordAuditEntry $audit) {}

    public function index(): Response
    {
        $meters = UsageMeter::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (UsageMeter $meter) => [
                'code' => (string) $meter->code,
                'name' => (string) $meter->name,
            ])->values()->all();

        $plans = Plan::query()
            ->with(['billingRates.usageMeter'])
            ->orderBy('base_price')
            ->get()
            ->map(fn (Plan $plan) => [
                'id' => (int) $plan->id,
                'code' => (string) $plan->code,
                'name' => (string) $plan->name,
                'basePrice' => (float) $plan->base_price,
                'isActive' => (bool) $plan->is_active,
                'limits' => $plan->billingRates
                    ->filter(fn (BillingRate $rate) => $rate->usageMeter !== null)
                    ->mapWithKeys(fn (BillingRate $rate) => [
                        $rate->usageMeter->code => (int) $rate->included_quantity,
                    ])->all(),
            ])->values()->all();

        return Inertia::render('admin/plans/index', [
            'plans' => $plans,
            'meters' => $meters,
        ]);
    }

    public function update(Request $request, Plan $plan, UpdatePlanLimits $updateLimits): RedirectResponse
    {
        $data = $request->validate([
            'limits' => ['required', 'array'],
            'limits.*' => ['integer', 'min:0'],
        ]);

        /** @var array<string, int> $limits */
        $limits = $data['limits'];

        $updateLimits->execute($plan, $limits);

        $user = $request->user();

        $this->audit->execute(
            actorType: AuditActorType::User,
            actorId: (int) $user->id,
            action: 'plan.limits_updated',
            category: AuditCategory::Billing,
            entityType: Plan::class,
            entityId: (int) $plan->id,
            summary: "Límites del plan {$plan->name} actualizados.",
            metadata: ['actor_email' => $user->email, 'limits' => $limits],
            signature: 'plan.limits_updated:'.$plan->id.':'.Str::uuid()->toString(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return redirect()
            ->route('admin.plans.index')
            ->with('status', 'Plan actualizado.');
    }
}
