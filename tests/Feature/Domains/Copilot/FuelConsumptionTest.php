<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Copilot\Support\FuelConsumption;
use Tests\TestCase;

class FuelConsumptionTest extends TestCase
{
    public function test_sums_drops_and_ignores_refuels(): void
    {
        $base = new \DateTimeImmutable('2026-09-30 08:00');
        $series = collect([[80, 0], [70, 60], [95, 120], [60, 180]])->map(fn ($p) => new AssetTelemetrySnapshot([
            'data_json' => ['value' => $p[0]],
            'recorded_at' => $base->modify("+{$p[1]} minutes"),
        ]));

        $result = FuelConsumption::fromSeries($series);

        $this->assertSame(45.0, $result['consumed']);
        $this->assertCount(1, $result['refuels']);
        $this->assertCount(1, $result['drops']);
    }
}
