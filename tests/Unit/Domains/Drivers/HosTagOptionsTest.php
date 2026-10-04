<?php

namespace Tests\Unit\Domains\Drivers;

use App\Domains\Drivers\Support\HosTagOptions;
use PHPUnit\Framework\TestCase;

class HosTagOptionsTest extends TestCase
{
    /**
     * @param  list<string>  $vehicles
     * @param  list<string>  $drivers
     * @return array{id: string, name: string, parent_id: string|null, vehicle_ids: list<string>, driver_ids: list<string>}
     */
    private static function tag(string $id, string $name, ?string $parent = null, array $vehicles = [], array $drivers = []): array
    {
        return ['id' => $id, 'name' => $name, 'parent_id' => $parent, 'vehicle_ids' => $vehicles, 'driver_ids' => $drivers];
    }

    public function test_children_follow_their_parent_with_its_name_and_kind(): void
    {
        $options = HosTagOptions::present([
            self::tag('3', 'LOCAL HT', '2', drivers: ['9']),
            self::tag('1', 'USA', drivers: ['7', '8']),
            self::tag('2', 'LOCAL JC', vehicles: ['281'], drivers: ['9']),
            self::tag('4', 'TRACTOS USA', vehicles: ['281', '282']),
            self::tag('5', 'Vacío'),
        ]);

        $this->assertSame(['2', '3', '4', '1', '5'], array_column($options, 'id'));
        $this->assertSame([
            'id' => '3', 'name' => 'LOCAL HT', 'parentId' => '2', 'parentName' => 'LOCAL JC',
            'depth' => 1, 'kind' => 'driver', 'vehicleCount' => 0, 'driverCount' => 1,
        ], $options[1]);
        $this->assertSame('both', $options[0]['kind']);
        $this->assertSame('vehicle', $options[2]['kind']);
        $this->assertSame(2, $options[3]['driverCount']);
        $this->assertSame('empty', $options[4]['kind']);
    }

    public function test_an_orphan_or_a_cycle_never_loses_a_tag_nor_loops(): void
    {
        $options = HosTagOptions::present([
            self::tag('1', 'A', '2'),
            self::tag('2', 'B', '1'),
            self::tag('3', 'C', '99'),
        ]);

        $this->assertSame(['3', '1', '2'], array_column($options, 'id'));
        $this->assertSame(0, $options[0]['depth']);
        $this->assertNull($options[0]['parentName']);
    }
}
