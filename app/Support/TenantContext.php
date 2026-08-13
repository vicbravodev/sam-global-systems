<?php

namespace App\Support;

use App\Models\Team;
use Closure;
use Illuminate\Support\Facades\Context;

/**
 * Tenant activo del proceso actual, independiente de que haya sesión HTTP.
 *
 * El scope global de BelongsToTenant se apoyaba sólo en `auth()->user()`, así
 * que en colas, listeners, comandos y scheduler no filtraba nada: ahí no hay
 * usuario autenticado. Esta clase guarda el tenant en el `Context` de Laravel,
 * que se deshidrata en el payload del job al despachar y se rehidrata en el
 * worker — de modo que un job despachado dentro de un tenant sigue dentro de
 * ese tenant, y el scope global vuelve a servir para algo.
 *
 * Regla: CLAUDE.md §2.1.
 */
class TenantContext
{
    public const KEY = 'tenant_id';

    /**
     * Valor centinela: "aquí NO hay tenant, y no busques uno en la sesión".
     * Distinto de la clave ausente, que sí deja caer al usuario autenticado.
     */
    private const SUPPRESSED = false;

    /**
     * Id del tenant activo, o null si el proceso no está dentro de ninguno.
     */
    public static function id(): ?int
    {
        $id = Context::get(self::KEY);

        return is_int($id) ? $id : null;
    }

    /**
     * ¿Se pidió explícitamente trabajar sin tenant? En ese caso `currentTeamId()`
     * no debe caer al usuario autenticado: el código quiere ver todos los tenants.
     */
    public static function isSuppressed(): bool
    {
        return Context::get(self::KEY) === self::SUPPRESSED;
    }

    /**
     * Fija el tenant activo para el resto del proceso (y para todo job que se
     * despache desde aquí). Pasar null limpia la clave y devuelve el control
     * al usuario autenticado; para trabajar sin tenant a propósito, usa
     * `withoutTenant()`.
     */
    public static function set(Team|int|null $team): void
    {
        $id = $team instanceof Team ? $team->id : $team;

        if ($id === null) {
            Context::forget(self::KEY);

            return;
        }

        Context::add(self::KEY, $id);
    }

    /**
     * Ejecuta el callback dentro del tenant dado y restaura el anterior al
     * salir. Es la forma correcta de iterar tenants en un job de plataforma.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function for(Team|int $team, Closure $callback): mixed
    {
        return self::restoring(fn () => self::set($team), $callback);
    }

    /**
     * Ejecuta el callback sin tenant activo: las queries ven todos los tenants.
     *
     * Úsalo SÓLO para trabajo de plataforma que legítimamente cruza tenants
     * (fan-out del scheduler, agregados de facturación, mantenimiento). Deja
     * la intención escrita, en vez de esconderla tras `withoutGlobalScopes()`.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutTenant(Closure $callback): mixed
    {
        return self::restoring(fn () => Context::add(self::KEY, self::SUPPRESSED), $callback);
    }

    /**
     * Aplica un cambio de contexto y restaura el estado exacto anterior al
     * salir, incluida la diferencia entre "sin clave" y "suprimido".
     *
     * @template TReturn
     *
     * @param  Closure(): void  $apply
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private static function restoring(Closure $apply, Closure $callback): mixed
    {
        $had = Context::has(self::KEY);
        $previous = Context::get(self::KEY);

        $apply();

        try {
            return $callback();
        } finally {
            $had ? Context::add(self::KEY, $previous) : Context::forget(self::KEY);
        }
    }
}
