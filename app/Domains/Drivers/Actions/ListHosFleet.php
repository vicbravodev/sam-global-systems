<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosUrgency;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Vista "HOS (EE. UU.)": los choferes con lectura reciente, del más urgente
 * al más holgado ({@see HosUrgency}), con un resumen por nivel. El estado de
 * quien sale del conjunto no se borra: deja de aparecer pasados
 * RECENT_SECONDS sin lectura y se marca `stale` pasados STALE_SECONDS; las
 * filas `stale` se listan pero no cuentan en los niveles del resumen (su
 * lectura ya no dice cómo está el chofer). `lastObservedAt` es la lectura más
 * reciente del team a cualquier edad: sin filas recientes, la pantalla dice
 * desde cuándo no hay lectura de Samsara en vez de "nadie en monitoreo".
 * Sólo choferes que siguen existiendo (los borrados salen de la vista).
 */
class ListHosFleet
{
    public const int RECENT_SECONDS = 1800;

    public const int STALE_SECONDS = 180;

    /**
     * @return array{rows: list<array<string, mixed>>, summary: array{total: int, violation: int, at_limit: int, warning: int, ok: int, stale: int}, lastObservedAt: string|null}
     */
    public function execute(int $teamId, CarbonInterface $now): array
    {
        return TenantContext::for($teamId, function () use ($teamId, $now): array {
            $states = HosDriverState::query()
                ->where('team_id', $teamId)
                ->whereHas('driver')
                ->where('observed_at', '>=', $now->toImmutable()->subSeconds(self::RECENT_SECONDS))
                ->with(['driver', 'asset'])
                ->get();

            $lastObserved = HosDriverState::query()
                ->where('team_id', $teamId)
                ->whereHas('driver')
                ->max('observed_at');
            $staleSince = $now->toImmutable()->subSeconds(self::STALE_SECONDS);

            $episodes = HosEpisode::query()
                ->where('team_id', $teamId)
                ->open()
                ->whereIn('driver_id', $states->pluck('driver_id')->all())
                ->orderBy('opened_at')
                ->get()
                ->groupBy('driver_id');

            $tally = [];
            $items = [];

            foreach ($states as $state) {
                $open = $episodes->get($state->driver_id, collect());
                $urgency = HosUrgency::level(
                    $state,
                    array_values($open->map(fn (HosEpisode $episode) => $episode->situation)->all()),
                    $open->contains(fn (HosEpisode $episode): bool => $episode->escalated_at !== null),
                );
                $name = $state->driver !== null ? $state->driver->full_name : 'Chofer sin nombre';
                $stale = $state->observed_at->lt($staleSince);
                $bucket = $stale ? 'stale' : $urgency;

                $tally[$bucket] = ($tally[$bucket] ?? 0) + 1;

                $items[] = [
                    'sort' => [HosUrgency::rank($urgency), HosUrgency::sortSeconds($state) ?? PHP_INT_MAX, mb_strtolower($name), $state->driver_id],
                    'row' => [
                        'driver' => ['id' => $state->driver_id, 'fullName' => $name],
                        'asset' => $state->asset !== null ? [
                            'id' => $state->asset->id,
                            'name' => $state->asset->name,
                            'code' => $state->asset->code,
                        ] : null,
                        'dutyStatus' => $state->duty_status?->value,
                        'appDisconnected' => $state->app_disconnected_since !== null,
                        'observedAt' => $state->observed_at->toIso8601String(),
                        'stale' => $stale,
                        'clocks' => $state->clockSnapshot(),
                        'violationSeconds' => $state->violation_s,
                        'urgency' => $urgency,
                        'minRemainingSeconds' => HosUrgency::minRemaining($state),
                        'openEpisodes' => array_values($open->map(fn (HosEpisode $episode): array => [
                            'id' => $episode->id,
                            'situation' => $episode->situation->value,
                            'ladderStep' => $episode->ladder_step,
                            'escalated' => $episode->escalated_at !== null,
                        ])->all()),
                    ],
                ];
            }

            usort($items, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

            return [
                'rows' => array_map(fn (array $item): array => $item['row'], $items),
                'summary' => [
                    'total' => count($items),
                    HosUrgency::VIOLATION => $tally[HosUrgency::VIOLATION] ?? 0,
                    HosUrgency::AT_LIMIT => $tally[HosUrgency::AT_LIMIT] ?? 0,
                    HosUrgency::WARNING => $tally[HosUrgency::WARNING] ?? 0,
                    HosUrgency::OK => $tally[HosUrgency::OK] ?? 0,
                    'stale' => $tally['stale'] ?? 0,
                ],
                'lastObservedAt' => $lastObserved !== null ? CarbonImmutable::parse((string) $lastObserved)->toIso8601String() : null,
            ];
        });
    }
}
