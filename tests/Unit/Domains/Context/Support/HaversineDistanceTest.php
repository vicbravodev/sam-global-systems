<?php

namespace Tests\Unit\Domains\Context\Support;

use App\Domains\Context\Support\HaversineDistance;
use PHPUnit\Framework\TestCase;

class HaversineDistanceTest extends TestCase
{
    public function test_returns_zero_for_identical_coordinates(): void
    {
        $this->assertSame(0.0, HaversineDistance::meters(19.4326, -99.1332, 19.4326, -99.1332));
    }

    public function test_computes_known_distance_between_mexico_city_and_guadalajara(): void
    {
        $distance = HaversineDistance::meters(19.4326, -99.1332, 20.6597, -103.3496);

        $this->assertEqualsWithDelta(461_000.0, $distance, 5_000.0);
    }

    public function test_symmetry(): void
    {
        $a = HaversineDistance::meters(40.7128, -74.0060, 51.5074, -0.1278);
        $b = HaversineDistance::meters(51.5074, -0.1278, 40.7128, -74.0060);

        $this->assertEqualsWithDelta($a, $b, 0.001);
    }

    public function test_short_distance(): void
    {
        $distance = HaversineDistance::meters(0.0, 0.0, 0.0, 0.001);

        $this->assertEqualsWithDelta(111.0, $distance, 2.0);
    }

    public function test_antipodal_points(): void
    {
        $distance = HaversineDistance::meters(0.0, 0.0, 0.0, 180.0);

        $this->assertEqualsWithDelta(20_015_000.0, $distance, 10_000.0);
    }
}
