<?php

namespace Tests\Unit\Domains\Context\Support;

use App\Domains\Context\Support\PointInPolygon;
use PHPUnit\Framework\TestCase;

class PointInPolygonTest extends TestCase
{
    public function test_returns_false_for_polygon_with_fewer_than_three_vertices(): void
    {
        $this->assertFalse(PointInPolygon::contains([], 0.0, 0.0));
        $this->assertFalse(PointInPolygon::contains([[0.0, 0.0]], 0.0, 0.0));
        $this->assertFalse(PointInPolygon::contains([[0.0, 0.0], [1.0, 1.0]], 0.0, 0.0));
    }

    public function test_point_inside_square_returns_true(): void
    {
        $square = [
            [0.0, 0.0],
            [10.0, 0.0],
            [10.0, 10.0],
            [0.0, 10.0],
            [0.0, 0.0],
        ];

        $this->assertTrue(PointInPolygon::contains($square, 5.0, 5.0));
    }

    public function test_point_outside_square_returns_false(): void
    {
        $square = [
            [0.0, 0.0],
            [10.0, 0.0],
            [10.0, 10.0],
            [0.0, 10.0],
        ];

        $this->assertFalse(PointInPolygon::contains($square, 15.0, 5.0));
        $this->assertFalse(PointInPolygon::contains($square, -1.0, 5.0));
        $this->assertFalse(PointInPolygon::contains($square, 5.0, 20.0));
    }

    public function test_point_inside_concave_polygon(): void
    {
        $concave = [
            [0.0, 0.0],
            [10.0, 0.0],
            [10.0, 10.0],
            [5.0, 5.0],
            [0.0, 10.0],
        ];

        $this->assertTrue(PointInPolygon::contains($concave, 1.0, 1.0));
        $this->assertFalse(PointInPolygon::contains($concave, 7.0, 5.0));
    }

    public function test_point_in_real_world_coordinates(): void
    {
        $mexicoCityPolygon = [
            [-99.20, 19.30],
            [-99.05, 19.30],
            [-99.05, 19.50],
            [-99.20, 19.50],
            [-99.20, 19.30],
        ];

        $this->assertTrue(PointInPolygon::contains($mexicoCityPolygon, 19.40, -99.13));
        $this->assertFalse(PointInPolygon::contains($mexicoCityPolygon, 20.00, -99.13));
    }

    public function test_degenerate_horizontal_edges_do_not_explode(): void
    {
        $polygon = [
            [0.0, 0.0],
            [10.0, 0.0],
            [10.0, 0.0],
            [10.0, 10.0],
            [0.0, 10.0],
        ];

        $this->assertTrue(PointInPolygon::contains($polygon, 5.0, 5.0));
    }
}
