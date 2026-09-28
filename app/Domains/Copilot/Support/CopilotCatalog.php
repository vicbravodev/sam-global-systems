<?php

namespace App\Domains\Copilot\Support;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Assets\Models\Asset;
use App\Domains\Copilot\Enums\CopilotIntent;
use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Queries\CopilotQuotaQuery;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * Boot data for the Copilot UI (page and bubble): the user's threads, the
 * fleet catalog behind the unit pickers and the query templates.
 */
class CopilotCatalog
{
    private const CONVERSATIONS_LIMIT = 40;

    private const ASSETS_LIMIT = 1000;

    public function __construct(
        private readonly AuthorizeAction $authorizeAction,
        private readonly CopilotQuotaQuery $quota,
        private readonly Gate $gate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(Team $team, User $user): array
    {
        $permissions = $this->authorizeAction->resolvePermissions($user, $team);
        $canSeeAssets = $user->isSuperAdmin() || in_array('assets.view', $permissions, true);

        $conversations = CopilotConversation::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->orderByDesc('is_pinned')
            ->orderByDesc('last_message_at')
            ->limit(self::CONVERSATIONS_LIMIT)
            ->get()
            ->map(fn (CopilotConversation $c) => CopilotMessagePresenter::conversation($c))
            ->all();

        $assets = $canSeeAssets
            ? Asset::query()
                ->where('team_id', $team->id)
                ->with('assetType')
                ->orderBy('code')
                ->orderBy('name')
                ->limit(self::ASSETS_LIMIT)
                ->get(['id', 'team_id', 'code', 'name', 'status', 'asset_type_id'])
                ->map(fn (Asset $asset) => [
                    'id' => (int) $asset->id,
                    'code' => $asset->code,
                    'name' => (string) $asset->name,
                    'category' => $asset->assetType?->category->value,
                    'status' => $asset->status->value,
                ])
                ->all()
            : [];

        $provider = (string) config('ai.default');
        $hasLlm = is_string(config("ai.providers.{$provider}.key")) && config("ai.providers.{$provider}.key") !== '';

        return [
            'conversations' => $conversations,
            'assets' => $assets,
            'templates' => $this->templates(),
            'suggestions' => $this->suggestions(),
            'quota' => $this->quota->forTeam($team->id),
            'engine' => [
                'mode' => $hasLlm ? 'llm' : 'grounded',
                'model' => $hasLlm ? config("ai.providers.{$provider}.models.text.default") : null,
            ],
            'canViewUsage' => $this->gate->forUser($user)->allows('viewUsage', CopilotConversation::class),
        ];
    }

    /**
     * Guided queries shown as pills: pick a template, pick a unit, send.
     *
     * @return list<array{intent: string, label: string, icon: string, needsAsset: bool, prompt: string}>
     */
    private function templates(): array
    {
        $definitions = [
            [CopilotIntent::AssetReport, 'Reporte completo', 'file-text', 'Dame el reporte completo de la unidad {asset}'],
            [CopilotIntent::AssetLocation, '¿Dónde está?', 'map-pin', '¿Dónde está la unidad {asset}?'],
            [CopilotIntent::AssetMedia, 'Última media', 'video', 'Muéstrame la última media de la unidad {asset}'],
            [CopilotIntent::EngineStats, 'Motor', 'gauge', 'Estadísticas de motor de la unidad {asset} esta semana'],
            [CopilotIntent::FuelReport, 'Combustible', 'fuel', 'Reporte de combustible de la unidad {asset} últimos 7 días'],
            [CopilotIntent::PanicKpis, 'Botones de pánico', 'siren', 'KPIs de los últimos botones de pánico'],
            [CopilotIntent::OpenIncidents, 'Incidentes abiertos', 'inbox', '¿Cuántos incidentes críticos hay abiertos ahora?'],
            [CopilotIntent::FleetOverview, 'Estado de la flota', 'truck', '¿Cómo está la flota ahora mismo?'],
            [CopilotIntent::DriverRanking, 'Ranking conductores', 'users', 'Ranking de conductores por riesgo'],
        ];

        return array_map(fn (array $d) => [
            'intent' => $d[0]->value,
            'label' => $d[1],
            'icon' => $d[2],
            'needsAsset' => $d[0]->requiresAsset(),
            'prompt' => $d[3],
        ], $definitions);
    }

    /**
     * @return list<array{group: string, prompts: list<string>}>
     */
    private function suggestions(): array
    {
        return [
            ['group' => 'Unidades', 'prompts' => [
                'Dame el reporte completo de la unidad {asset}',
                '¿Dónde están mis remolques?',
                'Muéstrame la última media de la unidad {asset}',
            ]],
            ['group' => 'Seguridad', 'prompts' => [
                'KPIs de los últimos botones de pánico',
                '¿Cuántos incidentes críticos hay abiertos ahora?',
                'Botones de pánico de hoy',
            ]],
            ['group' => 'Motor y combustible', 'prompts' => [
                'Reporte de combustible de la unidad {asset} últimos 7 días',
                'Estadísticas de motor de la unidad {asset} esta semana',
            ]],
            ['group' => 'Operación', 'prompts' => [
                '¿Cómo está la flota ahora mismo?',
                'Ranking de conductores por riesgo',
            ]],
        ];
    }
}
