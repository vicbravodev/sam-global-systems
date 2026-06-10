<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(AccessSeeder::class);
        $this->call(AssetTypeSeeder::class);
        $this->call(AIMeterSeeder::class);
        $this->call(DecisionOutcomeSeeder::class);
        $this->call(IncidentsSeeder::class);
        $this->call(NotificationMeterSeeder::class);
        $this->call(AssetMeterSeeder::class);
        $this->call(IngestionMeterSeeder::class);
        $this->call(ContextMeterSeeder::class);
        // PlanSeeder must run after every *MeterSeeder so meter codes resolve.
        $this->call(PlanSeeder::class);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
