<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Tenancy\Actions\ChangeTenantPlan;
use App\Domains\Tenancy\Actions\ExtendTrial;
use App\Domains\Tenancy\Actions\UpdateSubscriptionStatus;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Subscription;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Super-admin subscription controls for a single tenant (internal billing state
 * only — no live Stripe calls). Every mutation is written to the audit log under
 * the billing category.
 */
class TenantSubscriptionController extends Controller
{
    public function __construct(private readonly RecordAuditEntry $audit) {}

    public function update(Request $request, Team $team, ChangeTenantPlan $changePlan): RedirectResponse
    {
        $data = $request->validate([
            'plan_code' => ['required', 'string', 'exists:plans,code'],
        ]);

        $changePlan->execute($team, $data['plan_code']);

        $this->record($request, $team, 'tenant.plan_changed',
            "Plan del tenant {$team->name} cambiado a {$data['plan_code']}.",
            ['plan_code' => $data['plan_code']],
        );

        return $this->backToTenant($team, 'Plan actualizado.');
    }

    public function suspend(Request $request, Team $team, UpdateSubscriptionStatus $updateStatus): RedirectResponse
    {
        return $this->transition($request, $team, $updateStatus, SubscriptionStatus::Suspended,
            'tenant.subscription_suspended', 'suspendido');
    }

    public function reactivate(Request $request, Team $team, UpdateSubscriptionStatus $updateStatus): RedirectResponse
    {
        return $this->transition($request, $team, $updateStatus, SubscriptionStatus::Active,
            'tenant.subscription_reactivated', 'reactivado');
    }

    public function cancel(Request $request, Team $team, UpdateSubscriptionStatus $updateStatus): RedirectResponse
    {
        return $this->transition($request, $team, $updateStatus, SubscriptionStatus::Canceled,
            'tenant.subscription_canceled', 'cancelado');
    }

    public function extendTrial(Request $request, Team $team, ExtendTrial $extendTrial): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $subscription = $this->subscriptionFor($team);

        if ($subscription === null) {
            return $this->backToTenant($team, 'El tenant no tiene suscripción.', error: true);
        }

        $extendTrial->execute($subscription, (int) $data['days']);

        $this->record($request, $team, 'tenant.trial_extended',
            "Trial del tenant {$team->name} extendido {$data['days']} días.",
            ['days' => (int) $data['days']],
        );

        return $this->backToTenant($team, 'Trial extendido.');
    }

    private function transition(
        Request $request,
        Team $team,
        UpdateSubscriptionStatus $updateStatus,
        SubscriptionStatus $status,
        string $action,
        string $verb,
    ): RedirectResponse {
        $subscription = $this->subscriptionFor($team);

        if ($subscription === null) {
            return $this->backToTenant($team, 'El tenant no tiene suscripción.', error: true);
        }

        $updateStatus->execute($subscription, $status);

        $this->record($request, $team, $action,
            "Suscripción del tenant {$team->name} {$verb}.",
            ['status' => $status->value],
        );

        return $this->backToTenant($team, 'Suscripción actualizada.');
    }

    private function subscriptionFor(Team $team): ?Subscription
    {
        return Subscription::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->orderByDesc('starts_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(Request $request, Team $team, string $action, string $summary, array $metadata): void
    {
        $user = $request->user();

        $this->audit->execute(
            actorType: AuditActorType::User,
            actorId: (int) $user->id,
            action: $action,
            category: AuditCategory::Billing,
            entityType: Team::class,
            entityId: (int) $team->id,
            summary: $summary,
            teamId: (int) $team->id,
            metadata: ['actor_email' => $user->email] + $metadata,
            signature: $action.':'.$team->id.':'.Str::uuid()->toString(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }

    private function backToTenant(Team $team, string $message, bool $error = false): RedirectResponse
    {
        return redirect()
            ->route('admin.tenants.show', $team)
            ->with($error ? 'error' : 'status', $message);
    }
}
