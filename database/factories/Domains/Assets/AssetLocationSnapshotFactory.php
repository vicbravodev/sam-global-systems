<?php

namespace Database\Factories\Domains\Assets;

use App\Domains\Assets\Enums\LocationSource;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetLocationSnapshot>
 */
class AssetLocationSnapshotFactory extends Factory
{
    /**
     * Último `recorded_at` entregado por unidad. Los puntos son únicos por
     * unidad e instante (índice de idempotencia del feed): cada fila nueva de
     * la misma unidad cae estrictamente antes que la anterior, avance o no el
     * reloj entre creaciones (con `now() - n` dos filas creadas a un segundo
     * de distancia coincidían). Una cadena de hace más de 10 minutos es de un
     * test anterior (base de datos ya limpia) y reinicia en `now()`.
     *
     * @var array<int|string, CarbonImmutable>
     */
    private static array $lastByAsset = [];

    protected $model = AssetLocationSnapshot::class;

    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'speed' => fake()->randomFloat(2, 0, 120),
            'heading' => fake()->numberBetween(0, 359),
            'recorded_at' => fn (array $attributes): CarbonImmutable => self::nextRecordedAt($attributes['asset_id']),
            'source' => LocationSource::Provider,
        ];
    }

    private static function nextRecordedAt(int|string $assetId): CarbonImmutable
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $last = self::$lastByAsset[$assetId] ?? null;

        $next = match (true) {
            $last === null, $last->lessThan($now->subMinutes(10)) => $now,
            // El reloj retrocedió (travel/freeze): todo lo entregado es posterior.
            $now->lessThan($last) => $now,
            default => $last->subSecond(),
        };

        return self::$lastByAsset[$assetId] = $next;
    }

    public function fromGps(): static
    {
        return $this->state(fn () => [
            'source' => LocationSource::Gps,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn () => [
            'source' => LocationSource::Manual,
        ]);
    }
}
