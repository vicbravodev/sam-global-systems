<?php

namespace App\Domains\Context\Actions;

use App\Domains\Context\Enums\GeofenceMatchType;
use App\Domains\Context\Enums\GeofenceType;
use App\Domains\Context\Models\Geofence;
use App\Domains\Context\Support\HaversineDistance;
use App\Domains\Context\Support\PointInPolygon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ResolveGeofenceContext
{
    private const CACHE_TTL_SECONDS = 300;

    private const NEAR_BOUNDARY_METERS = 100;

    /**
     * Resolve which geofences contain (or are near) the given coordinates for a team.
     *
     * @return array<int, array<string, mixed>>
     */
    public function execute(?float $lat, ?float $lng, int $teamId): array
    {
        if ($lat === null || $lng === null) {
            return [];
        }

        $geofences = $this->loadActiveGeofences($teamId);
        $matches = [];

        foreach ($geofences as $geofence) {
            $match = $this->matchGeofence($geofence, $lat, $lng);

            if ($match !== null) {
                $matches[] = $match;
            }
        }

        return $matches;
    }

    /**
     * @return Collection<int, Geofence>
     */
    private function loadActiveGeofences(int $teamId): Collection
    {
        return Cache::remember(
            "team:{$teamId}:geofences",
            self::CACHE_TTL_SECONDS,
            fn () => Geofence::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('is_active', true)
                ->get(),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matchGeofence(Geofence $geofence, float $lat, float $lng): ?array
    {
        $geometry = $geofence->geometry_json ?? [];

        return match ($geofence->geofence_type) {
            GeofenceType::Zone, GeofenceType::Route => $this->matchZone($geofence, $geometry, $lat, $lng),
            GeofenceType::Point => $this->matchPoint($geofence, $geometry, $lat, $lng),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $geometry
     * @return array<string, mixed>|null
     */
    private function matchZone(Geofence $geofence, array $geometry, float $lat, float $lng): ?array
    {
        $coordinates = $geometry['coordinates'][0] ?? null;

        if (! is_array($coordinates) || count($coordinates) < 3) {
            return null;
        }

        if (! PointInPolygon::contains($coordinates, $lat, $lng)) {
            return null;
        }

        return [
            'geofence_id' => $geofence->id,
            'name' => $geofence->name,
            'code' => $geofence->code,
            'category' => $geofence->category,
            'match_type' => GeofenceMatchType::Inside,
            'distance_meters' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $geometry
     * @return array<string, mixed>|null
     */
    private function matchPoint(Geofence $geofence, array $geometry, float $lat, float $lng): ?array
    {
        $coordinates = $geometry['coordinates'] ?? null;
        $radiusMeters = $geometry['radius_meters'] ?? 0;

        if (! is_array($coordinates) || count($coordinates) !== 2) {
            return null;
        }

        $distance = HaversineDistance::meters($lat, $lng, (float) $coordinates[1], (float) $coordinates[0]);

        if ($distance <= (float) $radiusMeters) {
            return [
                'geofence_id' => $geofence->id,
                'name' => $geofence->name,
                'code' => $geofence->code,
                'category' => $geofence->category,
                'match_type' => GeofenceMatchType::Inside,
                'distance_meters' => (int) round($distance),
            ];
        }

        if ($distance <= (float) $radiusMeters + self::NEAR_BOUNDARY_METERS) {
            return [
                'geofence_id' => $geofence->id,
                'name' => $geofence->name,
                'code' => $geofence->code,
                'category' => $geofence->category,
                'match_type' => GeofenceMatchType::NearBoundary,
                'distance_meters' => (int) round($distance),
            ];
        }

        return null;
    }

    public static function invalidateCacheForTeam(int $teamId): void
    {
        Cache::forget("team:{$teamId}:geofences");
    }
}
