<?php

namespace Database\Seeders;

use App\Domains\Assets\Models\AssetType;
use Illuminate\Database\Seeder;

class AssetTypeSeeder extends Seeder
{
    /**
     * @var array<array{code: string, name: string, category: string, capabilities_json: array<string>}>
     */
    private const ASSET_TYPES = [
        [
            'code' => 'vehicle',
            'name' => 'Vehicle',
            'category' => 'vehicle',
            'capabilities_json' => ['gps', 'diagnostics', 'fuel', 'speed', 'ignition'],
        ],
        [
            'code' => 'trailer',
            'name' => 'Trailer',
            'category' => 'trailer',
            'capabilities_json' => ['gps', 'temperature', 'door_sensor'],
        ],
        [
            'code' => 'camera',
            'name' => 'Camera',
            'category' => 'camera',
            'capabilities_json' => ['video', 'motion_detection', 'night_vision'],
        ],
        [
            'code' => 'gps_device',
            'name' => 'GPS Device',
            'category' => 'gps_device',
            'capabilities_json' => ['gps', 'geofencing', 'battery'],
        ],
        [
            'code' => 'sensor',
            'name' => 'Sensor',
            'category' => 'sensor',
            'capabilities_json' => ['temperature', 'humidity', 'pressure'],
        ],
    ];

    public function run(): void
    {
        foreach (self::ASSET_TYPES as $typeData) {
            AssetType::updateOrCreate(
                ['code' => $typeData['code']],
                [
                    'name' => $typeData['name'],
                    'category' => $typeData['category'],
                    'capabilities_json' => $typeData['capabilities_json'],
                ],
            );
        }
    }
}
