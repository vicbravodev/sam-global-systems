<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Incidents\Support\EscalationLadder;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo que necesita la sección `?seccion=hos`: la configuración efectiva (la
 * guardada mezclada sobre `config('hos.defaults')`), los recomendados, las
 * unidades del team, los canales del chofer (con si SAM los entrega hoy a
 * este tenant) y si hay integración Samsara activa.
 */
class BuildHosConfigForm
{
    public function __construct(
        private readonly ResolveHosMonitoringConfig $resolveConfig,
    ) {}

    /**
     * @return array{config: array<string, mixed>, defaults: array<string, mixed>, assets: list<array{id: int, name: string, code: string|null, monitored: bool}>, channels: list<array{value: string, available: bool}>, hasIntegration: bool, canManage: bool, minGapMinutes: int}
     */
    public function execute(int $teamId, bool $canManage): array
    {
        return TenantContext::for($teamId, function () use ($teamId, $canManage): array {
            $defaults = HosMonitoringConfig::fromArray([], (array) config('hos.defaults'));
            $config = $this->resolveConfig->execute($teamId) ?? $defaults;

            $usable = NotificationChannel::query()
                ->usableByTeam($teamId)
                ->pluck('channel_type')
                ->map(fn (mixed $type): string => $type instanceof ChannelType ? $type->value : (string) $type)
                ->all();

            // Sólo unidades vivas del team (el scope ya quita las borradas).
            $assets = Asset::query()
                ->where('team_id', $teamId)
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'team_id', 'name', 'code', 'monitoring_state']);
            /** @var list<int> $liveIds */
            $liveIds = $assets->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

            return [
                'config' => self::present($config, $liveIds),
                'defaults' => self::present($defaults, $liveIds),
                'assets' => array_values($assets
                    ->map(fn (Asset $asset): array => [
                        'id' => $asset->id,
                        'name' => $asset->name,
                        'code' => $asset->code,
                        'monitored' => $asset->isMonitored(),
                    ])
                    ->all()),
                'channels' => array_map(fn (string $channel): array => [
                    'value' => $channel,
                    'available' => in_array($channel, $usable, true),
                ], HosMonitoringConfig::DRIVER_CHANNELS),
                'hasIntegration' => TenantIntegration::query()
                    ->where('team_id', $teamId)
                    ->where('status', TenantIntegrationStatus::Active)
                    ->whereHas('provider', fn (Builder $query) => $query->where('code', 'samsara'))
                    ->exists(),
                'canManage' => $canManage,
                'minGapMinutes' => EscalationLadder::MIN_GAP_MINUTES,
            ];
        });
    }

    /**
     * Lo que edita la pantalla, ya en la forma que acepta
     * `UpdateHosMonitoringConfigRequest`: sin unidades borradas o ajenas y sin
     * canales que no son del chofer (el correo de configuraciones viejas), para
     * que guardar lo presentado nunca falle por algo que no se ve.
     *
     * @param  list<int>  $liveAssetIds  unidades vivas del team
     * @return array{tagIds: list<string>, includedAssetIds: list<int>, excludedAssetIds: list<int>, situations: array<string, bool>, leadMinutes: list<int>, cycleLeadHours: list<int>, restCompleteNudgeMinutes: list<int>, restCompleteExpireMinutes: int, ladder: list<array{afterMinutes: int, channels: list<string>, escalate: bool}>}
     */
    public static function present(HosMonitoringConfig $config, array $liveAssetIds): array
    {
        $situations = [];

        foreach (HosMonitoringConfig::CONFIGURABLE_SITUATIONS as $key) {
            $situations[$key] = $config->enabled(HosSituation::from($key));
        }

        return [
            'tagIds' => array_values($config->tagIds),
            'includedAssetIds' => array_values(array_intersect($config->includedAssetIds, $liveAssetIds)),
            'excludedAssetIds' => array_values(array_intersect($config->excludedAssetIds, $liveAssetIds)),
            'situations' => $situations,
            // Sin el 0 del límite: la pantalla edita sólo los avisos previos.
            'leadMinutes' => $config->leadThresholdsMinutes(),
            'cycleLeadHours' => $config->cycleThresholdsHours(),
            'restCompleteNudgeMinutes' => $config->restNudgeMinutes(),
            'restCompleteExpireMinutes' => $config->restCompleteExpireMinutes,
            'ladder' => self::presentLadder($config),
        ];
    }

    /**
     * @return list<array{afterMinutes: int, channels: list<string>, escalate: bool}>
     */
    private static function presentLadder(HosMonitoringConfig $config): array
    {
        $ladder = [];

        foreach ($config->ladderSteps() as $step) {
            $channels = $step['escalate']
                ? []
                : array_values(array_intersect($step['channels'], HosMonitoringConfig::DRIVER_CHANNELS));

            if (! $step['escalate'] && $channels === []) {
                continue;
            }

            $ladder[] = [
                // El primero que queda sale al llegar al límite.
                'afterMinutes' => $ladder === [] ? 0 : $step['after_minutes'],
                'channels' => $channels,
                'escalate' => $step['escalate'],
            ];
        }

        return $ladder;
    }
}
