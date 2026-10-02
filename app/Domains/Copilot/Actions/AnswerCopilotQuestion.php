<?php

namespace App\Domains\Copilot\Actions;

use App\Domains\Assets\Enums\AssetCategory;
use App\Domains\Assets\Models\Asset;
use App\Domains\Copilot\Data\CopilotAnswer;
use App\Domains\Copilot\Data\CopilotPeriod;
use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Enums\CopilotIntent;
use App\Domains\Copilot\Support\AssetResolver;
use App\Domains\Copilot\Support\CopilotText;
use App\Domains\Copilot\Support\IntentRouter;
use App\Domains\Copilot\Tools\AssetActivityTool;
use App\Domains\Copilot\Tools\AssetEngineTool;
use App\Domains\Copilot\Tools\AssetFuelTool;
use App\Domains\Copilot\Tools\AssetLocationTool;
use App\Domains\Copilot\Tools\AssetMediaTool;
use App\Domains\Copilot\Tools\AssetSummaryTool;
use App\Domains\Copilot\Tools\AssetTimelineTool;
use App\Domains\Copilot\Tools\CopilotTool;
use App\Domains\Copilot\Tools\DriverRankingTool;
use App\Domains\Copilot\Tools\FleetOverviewTool;
use App\Domains\Copilot\Tools\OpenIncidentsTool;
use App\Domains\Copilot\Tools\PanicKpisTool;
use App\Domains\Copilot\Tools\SearchEventsTool;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves what a question is about (intent, unit, window) and runs the
 * read-only data tools that answer it. Pure read path: writes nothing.
 */
class AnswerCopilotQuestion
{
    /**
     * @var array<string, list<class-string<CopilotTool>>>
     */
    private const PLAN = [
        'asset_report' => [AssetSummaryTool::class, AssetLocationTool::class, AssetEngineTool::class, AssetFuelTool::class, AssetMediaTool::class, AssetActivityTool::class],
        'asset_location' => [AssetLocationTool::class],
        'asset_media' => [AssetMediaTool::class],
        'engine_stats' => [AssetEngineTool::class],
        'fuel_report' => [AssetFuelTool::class],
        'panic_kpis' => [PanicKpisTool::class],
        'open_incidents' => [OpenIncidentsTool::class],
        'driver_ranking' => [DriverRankingTool::class],
        'fleet_overview' => [FleetOverviewTool::class],
        // Ranking needs a metric only the agent picks; the deterministic path has nothing to run.
        'asset_ranking' => [],
        'event_search' => [SearchEventsTool::class],
        'asset_timeline' => [AssetTimelineTool::class],
        'general' => [],
    ];

    private const PICKER_LIMIT = 8;

    public function __construct(
        private readonly AssetResolver $assets,
        private readonly IntentRouter $router,
        private readonly Container $container,
    ) {}

    /**
     * @param  list<string>  $permissions
     * @param  array{asset_id?: int|null, intent?: string|null}  $hints  Explicit choices made in the UI (pills / selects).
     */
    public function execute(
        int $teamId,
        string $teamSlug,
        array $permissions,
        bool $isSuperAdmin,
        string $question,
        array $hints = [],
        ?int $previousAssetId = null,
    ): CopilotAnswer {
        return TenantContext::for($teamId, function () use ($teamId, $teamSlug, $permissions, $isSuperAdmin, $question, $hints, $previousAssetId): CopilotAnswer {
            $namedAsset = $this->assets->resolveFromPrompt($teamId, $question);
            $pinnedAsset = $this->assets->resolveById($teamId, $hints['asset_id'] ?? null);

            $explicit = isset($hints['intent']) ? CopilotIntent::tryFrom($hints['intent']) : null;
            $intent = $explicit ?? $this->router->route($question, $namedAsset !== null || $pinnedAsset !== null);

            // Unit questions use the unit named in the text, then the one pinned
            // in the composer, then the one the thread was already about
            // ("¿y su combustible?"). Fleet-wide questions (pánicos, incidentes)
            // only narrow to a unit when the text names it, so a unit left
            // pinned from a previous question never silently filters them.
            $asset = $intent->requiresAsset()
                ? ($namedAsset ?? $pinnedAsset ?? $this->assets->resolveById($teamId, $previousAssetId))
                : $namedAsset;

            $category = $this->assets->categoryFromPrompt($question);
            $period = CopilotPeriod::fromPrompt(CopilotText::normalize($question));

            $context = new CopilotToolContext(
                teamId: $teamId,
                teamSlug: $teamSlug,
                permissions: $permissions,
                period: $period,
                asset: $asset,
                category: $category,
                isSuperAdmin: $isSuperAdmin,
            );

            $resolved = [
                'asset_id' => $asset?->id,
                'asset_code' => $asset?->code,
                'category' => $category?->value,
                'period' => $period->label,
            ];

            // Cómo se enrutó la pregunta (camino determinista). Nunca el texto
            // de la pregunta ni el código de la unidad que escribió.
            $routing = [
                'team_id' => $teamId,
                'intent' => $intent->value,
                'intent_source' => $explicit !== null ? 'explicit_hint' : 'router',
                'asset_source' => match (true) {
                    ! $intent->requiresAsset() && $namedAsset === null => null,
                    $namedAsset !== null => 'named_in_question',
                    $pinnedAsset !== null => 'pinned_in_composer',
                    $asset !== null => 'previous_turn',
                    default => 'none',
                },
                'asset_id' => $asset?->id,
                'category' => $category?->value,
                'period_days' => $period->days,
            ];

            if ($intent->requiresAsset() && $asset === null) {
                SystemLog::skipped('copilot.intent.routed', reason: 'asset_required', input: $routing, result: [
                    'tools' => ['asset_picker'],
                ]);

                return new CopilotAnswer($intent, [$this->assetPicker($teamId, $intent, $category, $this->router->unitToken($question))], $resolved);
            }

            SystemLog::ok('copilot.intent.routed', input: $routing, result: [
                'tools' => array_map(class_basename(...), self::PLAN[$intent->value]),
            ]);

            $results = array_map(
                fn (string $tool) => $this->container->make($tool)->run($context),
                self::PLAN[$intent->value],
            );

            return new CopilotAnswer($intent, $results, $resolved);
        });
    }

    /**
     * The question needs one unit and none was named: offer the most
     * recently active ones as one-click choices.
     */
    private function assetPicker(int $teamId, CopilotIntent $intent, ?AssetCategory $category, ?string $unmatched): CopilotToolResult
    {
        $notFound = $unmatched !== null
            ? "No encontré la unidad {$unmatched} en tu flota. "
            : '';

        $query = Asset::query()->where('team_id', $teamId)->with('assetType');

        if ($category !== null) {
            $query->whereHas('assetType', fn ($q) => $q->where('category', $category->value));
        }

        $options = $query
            ->orderByRaw('CASE WHEN last_seen_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('last_seen_at')
            ->limit(self::PICKER_LIMIT)
            ->get()
            ->map(fn (Asset $asset) => [
                'id' => $asset->id,
                'code' => $asset->code,
                'name' => $asset->name,
                'category' => $asset->assetType?->category->value,
            ])
            ->all();

        return new CopilotToolResult(
            tool: 'asset_picker',
            label: 'Selección de unidad',
            blocks: [[
                'type' => 'asset_picker',
                'intent' => $intent->value,
                'text' => $notFound.'¿Sobre qué unidad? Elige una o escribe su número económico.',
                'options' => $options,
            ]],
            highlights: [$notFound.'Para el '.mb_strtolower($intent->label()).' necesito saber de qué unidad se trata. Elige una de la lista o escribe su número económico (por ejemplo, T555).'],
        );
    }
}
