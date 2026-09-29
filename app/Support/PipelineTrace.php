<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Traza de UN evento a lo largo del pipeline (webhook/poll → ingesta →
 * normalización → contexto → IA → decisión → incidente → automatización /
 * notificación), guardada en el `Context` de Laravel.
 *
 * El Context viaja solo en el payload de cada job encolado, así que cada línea
 * de log del recorrido lleva el mismo `trace_id`, el `team_id` del evento y los
 * ids de etapa que ya existan. Filtrar por `trace_id` devuelve el recorrido.
 *
 * Además el `trace_id` se persiste en `raw_events` y `normalized_events`: los
 * jobs que entran por el id de un evento lo ADOPTAN (`adopt()`), de modo que un
 * reintento, un replay o una reevaluación despachada desde otro sitio (una
 * petición HTTP del operador, un reconciliador) sigue en la traza original.
 *
 * Aquí sólo van ids y códigos: nunca teléfonos, nombres, payloads crudos ni
 * secretos. Lo interno que no deba salir en logs va en contexto oculto.
 */
final class PipelineTrace
{
    public const string TRACE_KEY = 'trace_id';

    public const string TEAM_KEY = 'team_id';

    /**
     * Traza de la operación (ciclo de poll, feed, barrido del scheduler) que
     * originó esta traza de evento: enlaza el ciclo con los eventos que levantó.
     */
    public const string PARENT_KEY = 'parent_trace_id';

    /**
     * Marca oculta: la traza actual se abrió para UN evento que aún no se ha
     * guardado (webhook recién recibido). El primer `StoreRawEvent` la reclama;
     * cualquier otro evento que se guarde después empieza su propia traza.
     */
    private const string CLAIMABLE_KEY = 'pipeline_trace_claimable';

    /**
     * Claves que describen una traza. `begin()` las limpia todas para que un
     * evento no herede ids de etapa de otro.
     *
     * @var list<string>
     */
    public const array KEYS = [
        self::TRACE_KEY,
        self::TEAM_KEY,
        self::PARENT_KEY,
        'provider',
        'provider_id',
        'webhook_event_id',
        'external_event_id',
        'raw_event_id',
        'normalized_event_id',
        'ai_evaluation_id',
        'decision_id',
        'incident_id',
        'workflow_execution_id',
        'action_execution_id',
        'notification_id',
    ];

    /**
     * Id de la traza activa, o null si el proceso no está dentro de ninguna.
     */
    public static function id(): ?string
    {
        $id = Context::get(self::TRACE_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Fija la traza activa (nueva, o `$traceId` si se retoma una) y descarta
     * todo id de etapa de la anterior. Úsalo en jobs y puntos de entrada; en
     * bucles, `within()`.
     */
    public static function begin(?int $teamId, ?string $provider = null, ?string $traceId = null): string
    {
        $traceId ??= self::newId();

        Context::forget(self::KEYS);
        Context::forgetHidden(self::CLAIMABLE_KEY);
        Context::add(array_filter([
            self::TRACE_KEY => $traceId,
            self::TEAM_KEY => $teamId,
            'provider' => $provider,
        ], static fn (mixed $value): bool => $value !== null));

        return $traceId;
    }

    /**
     * Traza de una OPERACIÓN (ciclo de poll, de feed, barrido del scheduler)
     * hija de la actual: cada evento que se guarde dentro abre su propia traza,
     * enlazada a ésta por `parent_trace_id`.
     */
    public static function beginOperation(?int $teamId, ?string $provider = null): string
    {
        $parent = self::parentFor($teamId);
        $traceId = self::begin($teamId, $provider);

        self::addParent($parent);

        return $traceId;
    }

    /**
     * Traza de UN evento que está por guardarse (un webhook recién recibido):
     * el `StoreRawEvent` de ese evento la reclama como suya.
     */
    public static function beginEvent(?int $teamId, ?string $provider = null): string
    {
        $traceId = self::beginOperation($teamId, $provider);

        Context::addHidden(self::CLAIMABLE_KEY, true);

        return $traceId;
    }

    /**
     * Ejecuta el callback dentro de la traza dada (null = una traza nueva) y
     * restaura el Context exactamente como estaba al salir. Si ya se está en
     * esa traza no toca nada: lo que se añada pertenece a la misma traza.
     *
     * Para bucles que procesan varios eventos seguidos (poll, reconciliador):
     * cada iteración queda en su traza y ninguna hereda ids de la anterior.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function within(?string $traceId, ?int $teamId, Closure $callback, ?string $provider = null): mixed
    {
        if ($traceId !== null && $traceId === self::id() && Context::get(self::TEAM_KEY) === $teamId) {
            return $callback();
        }

        $parent = self::parentFor($teamId);

        return Context::scope(function () use ($traceId, $teamId, $provider, $parent, $callback): mixed {
            self::begin($teamId, $provider, $traceId);
            self::addParent($parent);

            return $callback();
        });
    }

    /**
     * Traza para un evento que se va a guardar: la del webhook que lo trajo si
     * sigue sin reclamar y es del mismo tenant; si no, una nueva.
     */
    public static function claimForNewEvent(?int $teamId): string
    {
        $current = self::id();

        if ($current !== null
            && Context::getHidden(self::CLAIMABLE_KEY) === true
            && Context::get(self::TEAM_KEY) === $teamId
        ) {
            Context::forgetHidden(self::CLAIMABLE_KEY);

            return $current;
        }

        return self::newId();
    }

    /**
     * Entrada de un job que llega por el id de un registro: la traza guardada
     * en el evento manda (reintento, replay, reevaluación). Sin traza guardada
     * (registros sin columna, o anteriores a la migración) se conserva la que
     * trae el payload sólo si es del mismo tenant; si no, se abre una nueva,
     * así una traza nunca mezcla dos tenants.
     *
     * @param  array<string, int|string|null>  $ids
     */
    public static function adopt(?string $traceId, ?int $teamId, array $ids = []): string
    {
        $current = self::id();
        $currentTeam = Context::get(self::TEAM_KEY);

        if ($traceId !== null) {
            if ($traceId !== $current || $currentTeam !== $teamId) {
                self::begin($teamId, null, $traceId);
            }
        } elseif ($current === null || $currentTeam !== $teamId) {
            $parent = self::parentFor($teamId);

            self::begin($teamId);
            self::addParent($parent);
        }

        self::add($ids);

        return (string) self::id();
    }

    /**
     * Añade ids de etapa a la traza activa (los null se ignoran).
     *
     * @param  array<string, int|string|null>  $ids
     */
    public static function add(array $ids): void
    {
        $ids = array_filter($ids, static fn (mixed $value): bool => $value !== null && $value !== '');

        if ($ids !== []) {
            Context::add($ids);
        }
    }

    public static function newId(): string
    {
        return strtolower((string) Str::ulid());
    }

    /**
     * La traza actual, si puede ser padre de una traza de `$teamId`: una de
     * plataforma (sin tenant) o del mismo tenant. Nunca enlaza dos tenants.
     */
    private static function parentFor(?int $teamId): ?string
    {
        $team = Context::get(self::TEAM_KEY);

        return $team === null || $team === $teamId ? self::id() : null;
    }

    private static function addParent(?string $parent): void
    {
        if ($parent !== null && $parent !== self::id()) {
            Context::add(self::PARENT_KEY, $parent);
        }
    }
}
