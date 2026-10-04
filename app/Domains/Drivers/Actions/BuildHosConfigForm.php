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

            return [
                'config' => self::present($config),
                'defaults' => self::present($defaults),
                'assets' => array_values(Asset::query()
                    ->where('team_id', $teamId)
                    ->orderBy('name')
                    ->orderBy('id')
                    ->get(['id', 'team_id', 'name', 'code', 'monitoring_state'])
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
     * @return array{tagIds: array<int, string>, includedAssetIds: array<int, int>, excludedAssetIds: array<int, int>, situations: array<string, bool>, leadMinutes: list<int>, cycleLeadHours: list<int>, restCompleteNudgeMinutes: list<int>, restCompleteExpireMinutes: int, ladder: list<array{afterMinutes: int, channels: list<string>, escalate: bool}>}
     */
    public static function present(HosMonitoringConfig $config): array
    {
        $situations = [];

        foreach (HosMonitoringConfig::CONFIGURABLE_SITUATIONS as $key) {
            $situations[$key] = $config->enabled(HosSituation::from($key));
        }

        return [
            'tagIds' => $config->tagIds,
            'includedAssetIds' => $config->includedAssetIds,
            'excludedAssetIds' => $config->excludedAssetIds,
            'situations' => $situations,
            // Sin el 0 del límite: la pantalla edita sólo los avisos previos.
            'leadMinutes' => $config->leadThresholdsMinutes(),
            'cycleLeadHours' => $config->cycleThresholdsHours(),
            'restCompleteNudgeMinutes' => $config->restNudgeMinutes(),
            'restCompleteExpireMinutes' => $config->restCompleteExpireMinutes,
            'ladder' => array_map(fn (array $step): array => [
                'afterMinutes' => $step['after_minutes'],
                'channels' => $step['channels'],
                'escalate' => $step['escalate'],
            ], $config->ladderSteps()),
        ];
    }
}
