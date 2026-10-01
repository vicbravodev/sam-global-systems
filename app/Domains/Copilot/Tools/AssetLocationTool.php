<?php

namespace App\Domains\Copilot\Tools;

use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Support\CopilotPresenter;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Where a unit is right now, plus its recent route.
 *
 * The telematics feed stores a point every few seconds while a unit moves,
 * so "the last 30 points" would be a couple of minutes. The trail is instead
 * the last TRAIL_WINDOW_HOURS (never before the asked period) split into
 * TRAIL_LIMIT slots, one indexed lookup per slot for its newest point. A
 * "where is it" question wants the recent route, not a week-wide sketch.
 */
final class AssetLocationTool implements CopilotTool
{
    private const TRAIL_LIMIT = 30;

    private const TRAIL_WINDOW_HOURS = 2;

    public function run(CopilotToolContext $context): CopilotToolResult
    {
        if (! $context->can('assets.view')) {
            return CopilotToolResult::denied('asset_location', 'Ubicación GPS', 'activos');
        }

        $asset = $context->asset;
        abort_if($asset === null || $asset->team_id !== $context->teamId, 404);

        $trailFrom = $context->period->to->subHours(self::TRAIL_WINDOW_HOURS)->max($context->period->from);
        $trail = $this->sampledTrail($asset->id, $trailFrom, $context->period->to);

        $latest = AssetLocationSnapshot::query()
            ->where('asset_id', $asset->id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        $label = CopilotPresenter::assetLabel($asset);

        if ($latest === null) {
            return new CopilotToolResult(
                tool: 'asset_location',
                label: 'Ubicación GPS',
                blocks: [['type' => 'notice', 'tone' => 'info', 'text' => "{$label} todavía no reportó ninguna posición GPS."]],
                facts: ['asset' => $label, 'location' => null],
                highlights: ["{$label} no tiene posiciones GPS registradas."],
            );
        }

        $location = CopilotPresenter::location($latest);
        $motion = CopilotPresenter::motionState($latest);
        // Over every point of the period, not just the sampled ones.
        $maxSpeed = AssetLocationSnapshot::query()
            ->where('asset_id', $asset->id)
            ->whereBetween('recorded_at', [$context->period->from, $context->period->to])
            ->max('speed');

        $block = [
            'type' => 'location',
            'assetId' => $asset->id,
            'assetLabel' => $label,
            'motion' => $motion,
            'motionLabel' => CopilotPresenter::motionLabel($motion),
            ...$location,
            'maxSpeed' => $maxSpeed !== null ? round((float) $maxSpeed, 1) : null,
            'trail' => $trail->map(fn (AssetLocationSnapshot $s) => [
                'latitude' => (float) $s->latitude,
                'longitude' => (float) $s->longitude,
                'speed' => $s->speed !== null ? (float) $s->speed : null,
                'recordedAt' => $s->recorded_at->toIso8601String(),
            ])->all(),
            'href' => CopilotPresenter::assetHref($context->teamSlug, $asset->id),
        ];

        // Una dirección geocodificada nunca es '0': null o '' caen a las coordenadas.
        $where = $latest->formatted_location !== null && $latest->formatted_location !== '' ? $latest->formatted_location : sprintf('%.5f, %.5f', $location['latitude'], $location['longitude']);
        $speed = $location['speed'] !== null ? " a {$location['speed']} km/h" : '';

        return new CopilotToolResult(
            tool: 'asset_location',
            label: 'Ubicación GPS',
            blocks: [$block],
            sources: [[
                'kind' => 'asset',
                'id' => $asset->id,
                'label' => $label,
                'href' => CopilotPresenter::assetHref($context->teamSlug, $asset->id),
            ]],
            facts: [
                'asset' => $label,
                'location' => $where,
                'coordinates' => [$location['latitude'], $location['longitude']],
                'speed_kph' => $location['speed'],
                'recorded_at' => $location['recordedAt'],
                'motion' => $block['motionLabel'],
            ],
            highlights: [
                "Última posición de {$label}: {$where}{$speed}, ".CopilotPresenter::describeAge($location['recordedAt']).'.',
            ],
        );
    }

    /**
     * Up to TRAIL_LIMIT points, oldest first: the newest point of each of
     * TRAIL_LIMIT equal slots of the period. Slots without a point (parked,
     * no fix) are skipped.
     *
     * @return Collection<int, AssetLocationSnapshot>
     */
    private function sampledTrail(int $assetId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $span = max(1, $to->getTimestamp() - $from->getTimestamp());
        $trail = collect();

        for ($slot = 0; $slot < self::TRAIL_LIMIT; $slot++) {
            $slotStart = $from->addSeconds((int) floor($span * $slot / self::TRAIL_LIMIT));
            $slotEnd = $from->addSeconds((int) floor($span * ($slot + 1) / self::TRAIL_LIMIT));

            $point = AssetLocationSnapshot::query()
                ->where('asset_id', $assetId)
                ->where('recorded_at', '>=', $slotStart)
                ->where('recorded_at', $slot === self::TRAIL_LIMIT - 1 ? '<=' : '<', $slotEnd)
                ->orderByDesc('recorded_at')
                ->first();

            if ($point !== null) {
                $trail->push($point);
            }
        }

        return $trail;
    }
}
