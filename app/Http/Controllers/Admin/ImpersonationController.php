<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Starts/stops super-admin impersonation of a tenant. Impersonation is just a
 * force-switch of the operator's current team: once switched, every tenant page
 * under /{current_team} renders as that tenant via the BelongsToTenant scope.
 * Both transitions are written to the audit log (security category).
 */
class ImpersonationController extends Controller
{
    public function __construct(private readonly RecordAuditEntry $audit) {}

    public function store(Request $request, Team $team, #[CurrentUser] User $user): RedirectResponse
    {
        $user->forceSwitchTeam($team);

        $this->audit->execute(
            actorType: AuditActorType::User,
            actorId: $user->id,
            action: 'impersonation.started',
            category: AuditCategory::Security,
            entityType: Team::class,
            entityId: $team->id,
            summary: "Super-admin {$user->email} inició impersonación del tenant {$team->name}.",
            teamId: $team->id,
            metadata: ['actor_email' => $user->email, 'team_slug' => $team->slug],
            signature: 'impersonation:start:'.Str::uuid()->toString(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return redirect()->route('dashboard', $team);
    }

    public function destroy(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $impersonated = $user->currentTeam;
        $personal = $user->personalTeam();

        if ($personal !== null) {
            $user->forceSwitchTeam($personal);
        }

        $this->audit->execute(
            actorType: AuditActorType::User,
            actorId: $user->id,
            action: 'impersonation.stopped',
            category: AuditCategory::Security,
            entityType: Team::class,
            entityId: $impersonated?->id,
            summary: "Super-admin {$user->email} finalizó la impersonación.",
            teamId: $impersonated?->id,
            metadata: ['actor_email' => $user->email, 'team_slug' => $impersonated?->slug],
            signature: 'impersonation:stop:'.Str::uuid()->toString(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return redirect()->route('admin.tenants.index');
    }
}
