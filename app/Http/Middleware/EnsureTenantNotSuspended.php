<?php

namespace App\Http\Middleware;

use App\Domains\Tenancy\Support\CurrentSubscription;
use App\Models\Team;
use App\Models\User;
use App\Support\SystemLog;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta el acceso web de los miembros a un tenant cuya suscripción vigente
 * ({@see CurrentSubscription}) está SUSPENDIDA (decisión 2026-09-30): la
 * empresa no entra a su consola, pero SAM sigue vigilando sus pánicos — el
 * pipeline de ingesta/emergencias no pasa por aquí (webhooks y jobs).
 *
 * Va DESPUÉS de {@see EnsureTeamMembership} en los grupos `/{current_team}`:
 * un no-miembro recibe su 403 de siempre y nunca se entera de la suspensión.
 *
 * - Super-admins (operadores de SAM) nunca se bloquean: dan soporte y
 *   reactivan desde la consola.
 * - Responde 423 Locked: el recurso existe y el usuario es miembro, pero está
 *   bloqueado por su estado (reversible al reactivar); 403 lo confundiría con
 *   un problema de permisos del rol.
 * - `canceled` y `expired` no se tocan aquí: no hay decisión del usuario para
 *   ellos (siguen el criterio por módulo de AuthorizeAction).
 */
class EnsureTenantNotSuspended
{
    public const string REASON = 'tenant_suspended';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $team = $this->team($request);

        if (! $user instanceof User || $team === null || $user->isSuperAdmin()) {
            return $next($request);
        }

        if (! CurrentSubscription::isSuspended($team->id)) {
            return $next($request);
        }

        $wantsJson = $this->wantsJson($request);

        SystemLog::skipped('tenancy.web_access.denied', self::REASON, input: [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'route_name' => $request->route()?->getName(),
            'method' => $request->method(),
            'wants_json' => $wantsJson,
        ], debug: true);

        if ($wantsJson) {
            return response()->json([
                'message' => 'La cuenta de esta empresa está suspendida. Contacta a SAM para reactivarla.',
                'reason' => self::REASON,
            ], Response::HTTP_LOCKED);
        }

        return Inertia::render('errors/tenant-suspended', [
            'teamName' => $team->name,
            'otherTeams' => $this->otherAvailableTeams($user, $team),
        ])->toResponse($request)->setStatusCode(Response::HTTP_LOCKED);
    }

    /**
     * Otras empresas del usuario a las que sí puede entrar (no suspendidas),
     * para que cambie de equipo sin pasar por la consola bloqueada.
     *
     * @return array<int, array{name: string, slug: string, url: string}>
     */
    private function otherAvailableTeams(User $user, Team $team): array
    {
        return $user->teams()
            ->where('teams.id', '!=', $team->id)
            ->orderBy('teams.name')
            ->get(['teams.id', 'teams.name', 'teams.slug'])
            ->reject(fn (Team $other): bool => CurrentSubscription::isSuspended($other->id))
            ->map(fn (Team $other): array => [
                'name' => $other->name,
                'slug' => $other->slug,
                'url' => route('dashboard', ['current_team' => $other->slug]),
            ])
            ->values()
            ->all();
    }

    /**
     * Las visitas Inertia reciben la página; el resto de llamadas de la UI
     * (useHttp/fetch JSON, el stream SSE del Copiloto) y la API, JSON.
     */
    private function wantsJson(Request $request): bool
    {
        if ($request->header('X-Inertia') !== null) {
            return false;
        }

        return $request->expectsJson()
            || $request->is('api/*')
            || str_contains($request->header('Accept', ''), 'text/event-stream');
    }

    private function team(Request $request): ?Team
    {
        $team = $request->route('current_team');

        if (is_string($team)) {
            $team = Team::query()->where('slug', $team)->first();
        }

        return $team instanceof Team ? $team : null;
    }
}
