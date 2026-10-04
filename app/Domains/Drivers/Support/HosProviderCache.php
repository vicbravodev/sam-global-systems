<?php

namespace App\Domains\Drivers\Support;

use App\Domains\Drivers\Jobs\PollHosClocksJob;
use App\Domains\Drivers\Jobs\SyncHosClocksJob;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Data\HosClockReading;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Lo que la configuración HOS lee de Samsara sin pegarle en cada tecla: los
 * tags (`GET /tags`, la MISMA caché que usa el sondeo) y la última lectura de
 * relojes que deja {@see SyncHosClocksJob}. Las llaves llevan team e
 * integración.
 */
final readonly class HosProviderCache
{
    /** El sondeo corre cada minuto: 3 min cubren un ciclo perdido. */
    public const int READINGS_TTL_SECONDS = 180;

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
     * sondeo caído) una lectura directa que queda guardada.
     *
     * @return array{0: list<HosClockReading>, 1: 'cache'|'provider'}
     */
    public function readings(TenantIntegration $integration): array
    {
        $cached = Cache::get(self::readingsKey($integration->team_id, $integration->id));

        if (is_array($cached)) {
            return [
                array_values(array_map(
                    fn (array $row): HosClockReading => HosClockReading::fromArray($row),
                    array_filter($cached, 'is_array'),
                )),
                'cache',
            ];
        }

        $readings = array_values($this->providerAdapter->fetchHosClocks($integration));
        $this->putReadings($integration, $readings);

        return [$readings, 'provider'];
    }
}
