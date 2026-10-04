<?php

namespace App\Domains\Drivers\Support;

use App\Domains\Drivers\Jobs\PollHosClocksJob;
use App\Domains\Drivers\Jobs\SyncHosClocksJob;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Data\HosClockReading;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Exceptions\ProviderRequestFailed;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Domains\Integrations\Exceptions\ProviderUnavailable;
use App\Domains\Integrations\Models\TenantIntegration;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Lo que la configuración HOS lee de Samsara sin pegarle en cada tecla: los
 * tags (`GET /tags`, la MISMA caché que usa el sondeo) y la última lectura de
 * relojes que deja {@see SyncHosClocksJob}. Las llaves llevan team e
 * integración.
 *
 * Las lecturas de la configuración ({@see previewTags()}, {@see readings()})
 * cuidan la cuota de Samsara: con caché fría leen UNA vez bajo un lock (las
 * vistas previas concurrentes esperan esa lectura) y, si Samsara falla,
 * recuerdan el fallo {@see FAILURE_TTL_SECONDS} s y responden fallo sin
 * volver a pedir. El sondeo usa {@see tags()} y siempre intenta Samsara.
 */
final readonly class HosProviderCache
{
    /** El sondeo corre cada minuto: 3 min cubren un ciclo perdido. */
    public const int READINGS_TTL_SECONDS = 180;

    /** Tras un fallo de Samsara, la configuración no reintenta durante 30 s. */
    public const int FAILURE_TTL_SECONDS = 30;

    /** Vida del lock de lectura en frío (cubre la paginación de Samsara). */
    private const int LOCK_SECONDS = 20;

    /** Cuánto espera una vista previa a la lectura en curso de otra. */
    private const int LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private ProviderAdapter $providerAdapter,
    ) {}

    public static function tagsKey(int $teamId, int $integrationId): string
    {
        return "hos:tags:{$teamId}:{$integrationId}";
    }

    public static function readingsKey(int $teamId, int $integrationId): string
    {
        return "hos:clocks:{$teamId}:{$integrationId}";
    }

    /**
     * Fallo recordado por tipo de lectura: un fallo de relojes no tumba el
     * selector de etiquetas (ni al revés).
     *
     * @param  'tags'|'clocks'  $kind
     */
    public static function failureKey(string $kind, int $teamId, int $integrationId): string
    {
        return "hos:provider-failed:{$kind}:{$teamId}:{$integrationId}";
    }

    /**
     * @param  'tags'|'clocks'  $kind
     */
    public static function lockKey(string $kind, int $teamId, int $integrationId): string
    {
        return "hos:lock:{$kind}:{$teamId}:{$integrationId}";
    }

    /**
     * Integraciones Samsara activas del team (las que sondea {@see PollHosClocksJob}).
     *
     * @return Collection<int, TenantIntegration>
     */
    public function integrations(int $teamId): Collection
    {
        return TenantIntegration::query()
            ->where('team_id', $teamId)
            ->where('status', TenantIntegrationStatus::Active)
            ->whereHas('provider', fn (Builder $query) => $query->where('code', 'samsara'))
            ->with('provider')
            ->orderBy('id')
            ->get();
    }

    /**
     * Tags para el sondeo: ante caché fría siempre intenta Samsara (no lo
     * frena el fallo recordado de la configuración).
     *
     * @return array<int, array{id: string, name: string, parent_id: string|null, vehicle_ids: array<int, string>, driver_ids: array<int, string>}>
     */
    public function tags(TenantIntegration $integration): array
    {
        /** @var array<int, array{id: string, name: string, parent_id: string|null, vehicle_ids: array<int, string>, driver_ids: array<int, string>}> $tags */
        $tags = Cache::remember(
            self::tagsKey($integration->team_id, $integration->id),
            (int) config('hos.tags_cache_seconds', 300),
            fn (): array => $this->providerAdapter->fetchTags($integration),
        );

        return $tags;
    }

    /**
     * Tags para la configuración (selector y vista previa): la misma caché que
     * el sondeo, pero la lectura en frío va protegida (lock + fallo recordado).
     *
     * @return array<int, array{id: string, name: string, parent_id: string|null, vehicle_ids: array<int, string>, driver_ids: array<int, string>}>
     *
     * @throws ProviderRequestFailed|ProviderRequestFailedException
     */
    public function previewTags(TenantIntegration $integration): array
    {
        $key = self::tagsKey($integration->team_id, $integration->id);

        /** @var array<int, array{id: string, name: string, parent_id: string|null, vehicle_ids: array<int, string>, driver_ids: array<int, string>}> $tags */
        $tags = $this->guardedRead(
            $integration,
            'tags',
            fn (): ?array => is_array($cached = Cache::get($key)) ? $cached : null,
            function () use ($integration, $key): array {
                $tags = $this->providerAdapter->fetchTags($integration);
                Cache::put($key, $tags, (int) config('hos.tags_cache_seconds', 300));

                return $tags;
            },
        );

        return $tags;
    }

    /**
     * @param  array<int, HosClockReading>  $readings
     */
    public function putReadings(TenantIntegration $integration, array $readings): void
    {
        Cache::put(
            self::readingsKey($integration->team_id, $integration->id),
            array_map(fn (HosClockReading $reading): array => $reading->toArray(), array_values($readings)),
            self::READINGS_TTL_SECONDS,
        );
    }

    /**
     * La última lectura del sondeo; sin ella (feature recién encendida,
     * sondeo caído) una lectura directa protegida que queda guardada.
     *
     * @return array{0: list<HosClockReading>, 1: 'cache'|'provider'}
     *
     * @throws ProviderRequestFailed|ProviderRequestFailedException
     */
    public function readings(TenantIntegration $integration): array
    {
        // Sólo se vuelve `provider` si ESTA petición leyó Samsara.
        $fetched = false;

        /** @var list<HosClockReading> $readings */
        $readings = $this->guardedRead(
            $integration,
            'clocks',
            fn (): ?array => $this->cachedReadings($integration),
            function () use ($integration, &$fetched): array {
                $fetched = true;
                $readings = array_values($this->providerAdapter->fetchHosClocks($integration));
                $this->putReadings($integration, $readings);

                return $readings;
            },
        );

        return [$readings, $fetched ? 'provider' : 'cache'];
    }

    /**
     * @return list<HosClockReading>|null
     */
    private function cachedReadings(TenantIntegration $integration): ?array
    {
        $cached = Cache::get(self::readingsKey($integration->team_id, $integration->id));

        if (! is_array($cached)) {
            return null;
        }

        return array_values(array_map(
            fn (array $row): HosClockReading => HosClockReading::fromArray($row),
            array_filter($cached, 'is_array'),
        ));
    }

    /**
     * Lectura de la configuración: caché caliente sin lock ni fallo recordado;
     * en frío, si Samsara falló hace poco no lo vuelve a pedir; si otra
     * petición ya está leyendo, espera su resultado (re-lee la caché dentro
     * del lock); si falla, lo recuerda para este tipo de lectura.
     *
     * @template T of array
     *
     * @param  'tags'|'clocks'  $kind
     * @param  Closure(): (T|null)  $fromCache
     * @param  Closure(): T  $fetch
     * @return T
     *
     * @throws ProviderRequestFailed|ProviderRequestFailedException
     */
    private function guardedRead(TenantIntegration $integration, string $kind, Closure $fromCache, Closure $fetch): array
    {
        $warm = $fromCache();

        if ($warm !== null) {
            return $warm;
        }

        $failureKey = self::failureKey($kind, $integration->team_id, $integration->id);
        $this->throwIfRecentlyFailed($failureKey);

        try {
            return Cache::lock(self::lockKey($kind, $integration->team_id, $integration->id), self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, function () use ($failureKey, $fromCache, $fetch): array {
                    $cached = $fromCache();

                    if ($cached !== null) {
                        return $cached;
                    }

                    // Quien tenía el lock pudo fallar mientras esperábamos.
                    $this->throwIfRecentlyFailed($failureKey);

                    try {
                        return $fetch();
                    } catch (ProviderRequestFailed|ProviderRequestFailedException $e) {
                        Cache::put($failureKey, true, self::FAILURE_TTL_SECONDS);

                        throw $e;
                    }
                });
        } catch (LockTimeoutException $e) {
            throw new ProviderUnavailable('Another HOS configuration read of Samsara is still running.', previous: $e);
        }
    }

    private function throwIfRecentlyFailed(string $failureKey): void
    {
        if (Cache::has($failureKey)) {
            throw new ProviderUnavailable('Samsara failed recently; the HOS configuration does not retry yet.');
        }
    }
}
