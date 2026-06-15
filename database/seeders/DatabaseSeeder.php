<?php

namespace Database\Seeders;

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
        $this->call(NotificationTemplateSeeder::class);
        $this->call(AssetMeterSeeder::class);
        $this->call(IngestionMeterSeeder::class);
        $this->call(ContextMeterSeeder::class);
        $this->call(IncidentsMeterSeeder::class);
        // PlanSeeder must run after every *MeterSeeder so meter codes resolve.
        $this->call(PlanSeeder::class);

        // Samsara mapping rules so replayed/live webhook events normalize.
        $this->call(NormalizationSeeder::class);

        // Single dev tenant (ServiExpress JC) + panic_button→incident ruleset.
        $this->call(SamsaraTestSeeder::class);
        $this->call(SamsaraTestDecisionRulesSeeder::class);

        // Marca al admin del tenant de prueba como super-admin (acceso a /admin/*).
        // DEBE ir después de SamsaraTestSeeder, que es quien crea ese usuario.
        $this->call(SuperAdminSeeder::class);
    }
}
