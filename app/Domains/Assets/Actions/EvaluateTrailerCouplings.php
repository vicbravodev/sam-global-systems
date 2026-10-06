<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Enums\AssetCategory;
use App\Domains\Assets\Enums\CouplingDecoupleReason;
use App\Domains\Assets\Enums\CouplingSource;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetCoupling;
use App\Domains\Assets\Support\MovementCriterion;
use App\Domains\Context\Support\HaversineDistance;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decide qué tracto arrastra cada remolque de un tenant (decisión
 * 2026-10-05). Samsara no lo dice: sus asignaciones chofer–remolque llegan
 * vacías, así que se infiere por co-movimiento sobre el historial GPS de los
 * últimos minutos, sin estado propio — cada corrida vuelve a juzgar la
 * ventana completa y el resultado no depende de corridas perdidas.
 *
 * La cercanía sola no sirve: en un patio un tracto es el vecino más cercano
 * de decenas de cajas. Por eso sólo cuenta el remolque EN MOVIMIENTO, y cada
 * punto suyo se compara con la posición del tracto en ese mismo instante
 * (interpolada entre los dos puntos del tracto que lo rodean): a 90 km/h, un
 * desfase de un minuto entre lecturas ya son 1.5 km. Términos y umbrales en
 * `config('telematics.coupling')`.
 *
 * Un tracto lleva varios remolques (full: caja, dolly, caja); un remolque, un
 * tracto a la vez. Sólo los tractos vigilados tienen GPS guardado, así que
 * sólo ellos pueden enganchar.
 *
 * @phpstan-type Point array{lat: float, lng: float, speed: float|null, at: int}
 * @phpstan-type Comparison array{compared: int, matched: int, span_s: int, mean_distance_m: float|null, first_matched_at: int|null}
 */
class EvaluateTrailerCouplings
{
    /** Un tracto más lejos que esto del remolque ni se compara. */
    private const CANDIDATE_RADIUS_M = 10_000;

    /**
     * @return array{coupled: int, confirmed: int, decoupled: int, held: int}
     */
    public function execute(int $teamId): array
    {
        return TenantContext::for($teamId, fn () => $this->evaluate($teamId));
    }

    /**
     * @return array{coupled: int, confirmed: int, decoupled: int, held: int}
     */
    private function evaluate(int $teamId): array
    {
        $config = (array) config('telematics.coupling');
        $now = CarbonImmutable::now();
        $since = $now->subMinutes((int) $config['window_minutes']);
        $maxGap = (int) $config['max_bracket_gap_seconds'];
        $input = ['team_id' => $teamId];
        $counts = ['coupled' => 0, 'confirmed' => 0, 'decoupled' => 0, 'held' => 0];

        $open = AssetCoupling::query()
            ->where('team_id', $teamId)
            ->open()
            ->get()
            ->keyBy('trailer_asset_id');

        $trailers = Asset::query()
            ->where('team_id', $teamId)
            ->trailers()
            ->where(fn ($query) => $query
                ->where('last_location_at', '>=', $since)
                ->orWhereIn('id', $open->keys()->all()))
            ->get();

        if ($trailers->isEmpty()) {
            SystemLog::skipped('assets.trailer_coupling.evaluated', reason: 'no_recent_trailers', input: $input, calc: [
                'window_minutes' => (int) $config['window_minutes'],
            ], debug: true, channel: 'telematics');

            return $counts;
        }

        $tractors = Asset::query()
            ->where('team_id', $teamId)
            ->monitored()
            ->whereHas('assetType', fn ($query) => $query->where('category', AssetCategory::Vehicle))
            ->where(fn ($query) => $query
                ->where('last_location_at', '>=', $since)
                ->orWhereIn('id', $open->pluck('tractor_asset_id')->all()))
            ->get()
            ->keyBy('id');

        $points = $this->points([...$trailers->modelKeys(), ...$tractors->keys()->all()], $since->subSeconds($maxGap));
        $changes = [];

        foreach ($trailers as $trailer) {
            $current = $open->get($trailer->id);
            $trailerPoints = array_values(array_filter(
                $points[$trailer->id] ?? [],
                fn (array $point) => $point['at'] >= $since->getTimestamp(),
            ));
            $moving = array_values(array_filter($trailerPoints, fn (array $point) => MovementCriterion::isMovingSpeed($point['speed'])));

            if (count($moving) < (int) $config['min_compared_points']) {
                // Quieto (o casi sin lecturas): el remolque no dice nada. Sólo
                // el tracto puede delatar que lo dejó atrás.
                $outcome = $this->judgeLeftBehind($trailer, $current, $tractors, $points, $since, $config, $now);
                $counts[$outcome === null ? 'held' : 'decoupled'] += $current !== null ? 1 : 0;

                if ($outcome !== null) {
                    $changes[] = $outcome;
                }

                continue;
            }

            $best = $this->bestTractor($trailer, $moving, $tractors, $points, $config, $maxGap);

            if ($best !== null && $current?->tractor_asset_id === $best['tractor_id']) {
                $current->forceFill([
                    'last_confirmed_at' => $now,
                    'evidence_json' => $best['evidence'],
                ])->save();
                $counts['confirmed']++;

                continue;
            }

            if ($best !== null) {
                $changes[] = DB::transaction(function () use ($current, $best, $trailer, $teamId, $now, &$counts): array {
                    if ($current !== null) {
                        $this->decouple($current, CouplingDecoupleReason::Switched, $now, $best['evidence']);
                        $counts['decoupled']++;
                    }

                    $this->couple($teamId, $best['tractor_id'], $trailer->id, $best['evidence'], $now);
                    $counts['coupled']++;

                    return ['trailer_asset_id' => $trailer->id, 'tractor_asset_id' => $best['tractor_id']];
                });

                continue;
            }

            if ($current === null) {
                continue;
            }

            // Se mueve y ningún tracto lo acompaña: ¿se separó del suyo?
            $withCurrent = $this->compare($moving, $points[$current->tractor_asset_id] ?? [], (float) $config['match_radius_m'], $maxGap);
            $ratio = $withCurrent['compared'] > 0 ? $withCurrent['matched'] / $withCurrent['compared'] : null;

            if ($withCurrent['compared'] >= (int) $config['min_compared_points'] && $ratio !== null && $ratio <= (float) $config['decouple_ratio']) {
                $this->decouple($current, CouplingDecoupleReason::Diverged, $now, $this->evidence($withCurrent, $config));
                $counts['decoupled']++;
                $changes[] = ['trailer_asset_id' => $trailer->id, 'tractor_asset_id' => null];

                continue;
            }

            $counts['held']++;
        }

        SystemLog::ok('assets.trailer_coupling.evaluated', input: $input, calc: [
            'window_minutes' => (int) $config['window_minutes'],
            'match_radius_m' => (int) $config['match_radius_m'],
            'couple_ratio' => (float) $config['couple_ratio'],
            'decouple_ratio' => (float) $config['decouple_ratio'],
            'trailers_count' => $trailers->count(),
            'tractors_count' => $tractors->count(),
            'open_before_count' => $open->count(),
        ], result: [
            'coupled_count' => $counts['coupled'],
            'confirmed_count' => $counts['confirmed'],
            'decoupled_count' => $counts['decoupled'],
            'held_count' => $counts['held'],
        ], debug: $changes === [], channel: 'telematics');

        return $counts;
    }

    /**
     * El tracto que mejor acompaña al remolque en movimiento, si alguno pasa
     * el umbral de enganche.
     *
     * @param  list<Point>  $moving
     * @param  Collection<int, Asset>|\Illuminate\Support\Collection<int, Asset>  $tractors
     * @param  array<int, list<Point>>  $points
     * @param  array<string, mixed>  $config
     * @return array{tractor_id: int, evidence: array<string, mixed>}|null
     */
    private function bestTractor(Asset $trailer, array $moving, $tractors, array $points, array $config, int $maxGap): ?array
    {
        $last = array_last($moving);
        $best = null;

        if ($last === null) {
            return null;
        }

        foreach ($tractors as $tractor) {
            if ($tractor->last_latitude === null || $tractor->last_longitude === null
                || HaversineDistance::meters($last['lat'], $last['lng'], $tractor->last_latitude, $tractor->last_longitude) > self::CANDIDATE_RADIUS_M) {
                continue;
            }

            $comparison = $this->compare($moving, $points[$tractor->id] ?? [], (float) $config['match_radius_m'], $maxGap);

            if ($comparison['compared'] < (int) $config['min_compared_points']
                || $comparison['span_s'] < (int) $config['min_span_seconds']
                || (float) $config['couple_ratio'] > $comparison['matched'] / $comparison['compared']) {
                continue;
            }

            $ratio = $comparison['matched'] / $comparison['compared'];

            if ($best === null
                || $ratio > $best['ratio']
                || ($ratio === $best['ratio'] && ($comparison['mean_distance_m'] ?? INF) < ($best['comparison']['mean_distance_m'] ?? INF))) {
                $best = ['tractor_id' => $tractor->id, 'ratio' => $ratio, 'comparison' => $comparison];
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'tractor_id' => $best['tractor_id'],
            'evidence' => $this->evidence($best['comparison'], $config),
        ];
    }

    /**
     * Cada punto del remolque contra la posición del tracto en el mismo
     * instante. Un punto sin dos lecturas del tracto que lo rodeen a menos de
     * `$maxGap` segundos no es comparable (no se adivina dónde estaba).
     *
     * @param  list<Point>  $trailerPoints
     * @param  list<Point>  $tractorPoints  ordenados por tiempo
     * @return Comparison
     */
    private function compare(array $trailerPoints, array $tractorPoints, float $radius, int $maxGap): array
    {
        $compared = 0;
        $matched = 0;
        $distanceSum = 0.0;
        $first = null;
        $last = null;
        $firstMatched = null;

        foreach ($trailerPoints as $point) {
            $at = $this->positionAt($tractorPoints, $point['at'], $maxGap);

            if ($at === null) {
                continue;
            }

            $distance = HaversineDistance::meters($point['lat'], $point['lng'], $at['lat'], $at['lng']);
            $compared++;
            $distanceSum += $distance;
            $first ??= $point['at'];
            $last = $point['at'];

            if ($distance <= $radius) {
                $matched++;
                $firstMatched ??= $point['at'];
            }
        }

        return [
            'compared' => $compared,
            'matched' => $matched,
            'span_s' => $first !== null && $last !== null ? $last - $first : 0,
            'mean_distance_m' => $compared > 0 ? round($distanceSum / $compared, 1) : null,
            'first_matched_at' => $firstMatched,
        ];
    }

    /**
     * Posición interpolada en `$at` entre las lecturas que lo rodean.
     *
     * @param  list<Point>  $points  ordenados por tiempo
     * @return array{lat: float, lng: float}|null
     */
    private function positionAt(array $points, int $at, int $maxGap): ?array
    {
        $before = null;
        $after = null;

        foreach ($points as $point) {
            if ($point['at'] <= $at) {
                $before = $point;

                continue;
            }

            $after = $point;

            break;
        }

        if ($before !== null && $before['at'] === $at) {
            return ['lat' => $before['lat'], 'lng' => $before['lng']];
        }

        if ($before === null || $after === null || $maxGap < $after['at'] - $before['at']) {
            return null;
        }

        $fraction = ($at - $before['at']) / ($after['at'] - $before['at']);

        return [
            'lat' => $before['lat'] + ($after['lat'] - $before['lat']) * $fraction,
            'lng' => $before['lng'] + ($after['lng'] - $before['lng']) * $fraction,
        ];
    }

    /**
     * Remolque quieto: su enganche sólo se rompe si su tracto maneja lejos de
     * él. Mientras ambos están detenidos, el enganche se conserva.
     *
     * @param  \Illuminate\Support\Collection<int, Asset>  $tractors
     * @param  array<int, list<Point>>  $points
     * @param  array<string, mixed>  $config
     * @return array{trailer_asset_id: int, tractor_asset_id: null}|null
     */
    private function judgeLeftBehind(Asset $trailer, ?AssetCoupling $current, $tractors, array $points, CarbonInterface $since, array $config, CarbonImmutable $now): ?array
    {
        if ($current === null || $trailer->last_latitude === null || $trailer->last_longitude === null) {
            return null;
        }

        $tractor = $tractors->get($current->tractor_asset_id);
        $tractorMoving = array_values(array_filter(
            $points[$current->tractor_asset_id] ?? [],
            fn (array $point) => $point['at'] >= $since->getTimestamp() && MovementCriterion::isMovingSpeed($point['speed']),
        ));

        if ($tractor === null || $tractor->last_latitude === null || $tractor->last_longitude === null
            || count($tractorMoving) < (int) $config['min_compared_points']) {
            return null;
        }

        $distance = HaversineDistance::meters($trailer->last_latitude, $trailer->last_longitude, $tractor->last_latitude, $tractor->last_longitude);

        if ($distance <= (float) $config['left_behind_m']) {
            return null;
        }

        $this->decouple($current, CouplingDecoupleReason::LeftBehind, $now, [
            'distance_m' => round($distance, 1),
            'left_behind_m' => (int) $config['left_behind_m'],
            'tractor_moving_points' => count($tractorMoving),
        ]);

        return ['trailer_asset_id' => $trailer->id, 'tractor_asset_id' => null];
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function couple(int $teamId, int $tractorId, int $trailerId, array $evidence, CarbonImmutable $now): void
    {
        $firstMatched = $evidence['first_matched_at'] ?? null;

        $coupling = AssetCoupling::query()->create([
            'team_id' => $teamId,
            'tractor_asset_id' => $tractorId,
            'trailer_asset_id' => $trailerId,
            'source' => CouplingSource::CoMovement,
            'coupled_at' => is_string($firstMatched) ? CarbonImmutable::parse($firstMatched) : $now,
            'last_confirmed_at' => $now,
            'evidence_json' => $evidence,
        ]);

        SystemLog::ok('assets.trailer_coupling.coupled', input: [
            'team_id' => $teamId,
            'coupling_id' => $coupling->id,
            'tractor_asset_id' => $tractorId,
            'trailer_asset_id' => $trailerId,
        ], calc: $evidence, channel: 'telematics');
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function decouple(AssetCoupling $coupling, CouplingDecoupleReason $reason, CarbonImmutable $now, array $evidence): void
    {
        $coupling->forceFill([
            'decoupled_at' => $now,
            'decouple_reason' => $reason,
        ])->save();

        SystemLog::ok('assets.trailer_coupling.decoupled', input: [
            'team_id' => $coupling->team_id,
            'coupling_id' => $coupling->id,
            'tractor_asset_id' => $coupling->tractor_asset_id,
            'trailer_asset_id' => $coupling->trailer_asset_id,
        ], calc: ['decouple_reason' => $reason->value, ...$evidence], channel: 'telematics');
    }

    /**
     * @param  Comparison  $comparison
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function evidence(array $comparison, array $config): array
    {
        return [
            'compared_points' => $comparison['compared'],
            'matched_points' => $comparison['matched'],
            'match_ratio' => $comparison['compared'] > 0 ? round($comparison['matched'] / $comparison['compared'], 2) : null,
            'span_s' => $comparison['span_s'],
            'mean_distance_m' => $comparison['mean_distance_m'],
            'match_radius_m' => (int) $config['match_radius_m'],
            'first_matched_at' => $comparison['first_matched_at'] !== null
                ? CarbonImmutable::createFromTimestamp($comparison['first_matched_at'])->toIso8601String()
                : null,
        ];
    }

    /**
     * GPS de los activos dados desde `$from`, agrupado y ordenado por tiempo.
     * Los ids ya vienen filtrados por el tenant.
     *
     * @param  list<int>  $assetIds
     * @return array<int, list<Point>>
     */
    private function points(array $assetIds, CarbonInterface $from): array
    {
        $grouped = [];

        foreach (array_chunk(array_values(array_unique($assetIds)), 1000) as $chunk) {
            $rows = DB::table('asset_location_snapshots')
                ->whereIn('asset_id', $chunk)
                ->where('recorded_at', '>=', $from)
                ->orderBy('recorded_at')
                ->orderBy('id')
                ->get(['asset_id', 'latitude', 'longitude', 'speed', 'recorded_at']);

            foreach ($rows as $row) {
                $grouped[(int) $row->asset_id][] = [
                    'lat' => (float) $row->latitude,
                    'lng' => (float) $row->longitude,
                    'speed' => $row->speed !== null ? (float) $row->speed : null,
                    'at' => CarbonImmutable::parse($row->recorded_at)->getTimestamp(),
                ];
            }
        }

        return $grouped;
    }
}
