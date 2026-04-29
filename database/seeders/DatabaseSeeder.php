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

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
