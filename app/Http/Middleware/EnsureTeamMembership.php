<?php

namespace App\Http\Middleware;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamMembership
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $minimumRole = null): Response
    {
        [$user, $team] = [$request->user(), $this->team($request)];

        // Super-admins (SaaS operators) may open any tenant's pages to provide
        // support. We force-switch their current team to the one in the URL so
        // the BelongsToTenant global scope transparently scopes every query to
        // the impersonated tenant — no membership or role check applies.
        if ($user?->isSuperAdmin() === true && $team !== null) {
            if ($request->route('current_team') !== null && ! $user->isCurrentTeam($team)) {
                $user->forceSwitchTeam($team);

                // Entrar por URL directa también es impersonar: queda en la
                // auditoría igual que el botón "Entrar a su consola".
                if (! $user->belongsToTeam($team)) {
                    $this->recordImplicitImpersonation($request, $user, $team);
                }
            }

            TenantContext::set($team);

            return $next($request);
        }

        if ($user === null || $team === null || ! $user->belongsToTeam($team)) {
            // Sin usuario o sin team no hay a quién atribuirlo: lo cubre el
            // `http.request.denied` del 403. Un no-miembro sí se narra.
            if ($user !== null && $team !== null) {
                SystemLog::skipped('access.check.denied', reason: 'not_member', input: [
                    'user_id' => $user->id,
                    'team_id' => $team->id,
                    'route_name' => $request->route()?->getName(),
                ]);
            }

            abort(403);
        }

        $this->ensureTeamMemberHasRequiredRole($user, $team, $minimumRole);

        if ($request->route('current_team') !== null && ! $user->isCurrentTeam($team)) {
            $user->switchTeam($team);
        }

        // Fija el tenant en el Context para que lo hereden los jobs que se
        // despachen durante esta request: el worker rehidrata el Context y el
        // scope global sigue filtrando allí. Ver App\Support\TenantContext.
        TenantContext::set($team);

        return $next($request);
    }

    private function recordImplicitImpersonation(Request $request, User $user, Team $team): void
    {
        // Hasta ahora silencioso fuera de la auditoría: un operador cambió de
        // tenant sólo por abrir una URL. Va a `warning` para que se vea.
        SystemLog::degraded('access.super_admin.forced_team_switch', reason: 'direct_url', input: [
            'user_id' => $user->id,
            'team_id' => $team->id,
            'route_name' => $request->route()?->getName(),
        ]);

        app(RecordAuditEntry::class)->execute(
            actorType: AuditActorType::User,
            actorId: $user->id,
            action: 'impersonation.started',
            category: AuditCategory::Security,
            entityType: Team::class,
            entityId: $team->id,
            summary: "Super-admin {$user->email} entró al cliente {$team->name} por URL directa.",
            teamId: $team->id,
            metadata: ['actor_email' => $user->email, 'team_slug' => $team->slug, 'via' => 'direct_url'],
            signature: 'impersonation:start:'.Str::uuid()->toString(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }

    /**
     * Ensure the given user has at least the given role, if applicable.
     */
    protected function ensureTeamMemberHasRequiredRole(User $user, Team $team, ?string $minimumRole): void
    {
        if ($minimumRole === null) {
            return;
        }

        $role = $user->teamRole($team);

        $requiredRole = TeamRole::tryFrom($minimumRole);

        if ($requiredRole === null || $role === null || ! $role->isAtLeast($requiredRole)) {
            SystemLog::skipped('access.check.denied', reason: 'role', input: [
                'user_id' => $user->id,
                'team_id' => $team->id,
                'min_role' => $minimumRole,
                'role' => $role?->value,
            ]);

            abort(403);
        }
    }

    /**
     * Get the team associated with the request.
     */
    protected function team(Request $request): ?Team
    {
        $team = $request->route('current_team') ?? $request->route('team');

        if (is_string($team)) {
            $team = Team::where('slug', $team)->first();
        }

        return $team;
    }
}
