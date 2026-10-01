<?php

namespace App\Support;

use App\Models\Membership;
use App\Models\User;

/**
 * Resuelve el team actual del usuario autenticado (fallback HTTP de
 * `currentTeamId()` cuando no hay TenantContext).
 *
 * `current_team_id` es sólo una preferencia guardada: puede apuntar a un team
 * borrado o del que el usuario ya no es miembro. En ese caso NO hay team
 * actual (salvo para el super-admin, que opera tenants sin ser miembro).
 *
 * El chequeo de membresía se memoiza en los atributos de la request actual:
 * una query por request, y nunca un resultado viejo entre requests.
 */
class CurrentTeamResolver
{
    public static function idFor(User $user): ?int
    {
        $team = $user->currentTeam;

        if ($team === null) {
            return null;
        }

        if ($user->isSuperAdmin()) {
            return $team->id;
        }

        $isMember = fn (): bool => Membership::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->exists();

        // En procesos de larga vida (workers) la "request" del contenedor no
        // cambia entre jobs: ahí no se memoiza.
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return $isMember() ? $team->id : null;
        }

        $attributes = request()->attributes;
        $key = "sam.current_team.{$user->id}.{$team->id}";

        if (! $attributes->has($key)) {
            $attributes->set($key, $isMember());
        }

        // Sólo este método escribe la clave, siempre con el bool de $isMember().
        return $attributes->get($key) === true ? $team->id : null;
    }

    /**
     * ¿Debe el scope de tenant cerrarse (no devolver filas) en vez de abrirse?
     *
     * Sí cuando hay un usuario autenticado que no es super-admin y no tiene
     * team válido: sin esto, `currentTeamId()` null dejaba el scope global de
     * BelongsToTenant sin filtro y el usuario veía TODOS los tenants. En colas
     * y consola no hay usuario autenticado y el comportamiento no cambia; el
     * trabajo de plataforma usa `TenantContext::withoutTenant()`.
     */
    public static function shouldFailClosed(): bool
    {
        if (TenantContext::isSuppressed() || TenantContext::id() !== null) {
            return false;
        }

        $user = auth()->user();

        return $user instanceof User && ! $user->isSuperAdmin();
    }
}
