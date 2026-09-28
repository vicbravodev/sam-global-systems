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
        // Canales de plataforma operados por SAM (credenciales Twilio en env).
        $this->call(PlatformChannelSeeder::class);
        $this->call(AssetMeterSeeder::class);
        $this->call(IngestionMeterSeeder::class);
        $this->call(ContextMeterSeeder::class);
        $this->call(IncidentsMeterSeeder::class);
        $this->call(OtpMeterSeeder::class);
        // PlanSeeder must run after every *MeterSeeder so meter codes resolve.
        $this->call(PlanSeeder::class);

        // Samsara mapping rules so replayed/live webhook events normalize.
        $this->call(NormalizationSeeder::class);

        // Todo lo de abajo es de desarrollo/prueba (cuentas con contraseña
        // conocida y un super-admin): nunca en producción. Los catálogos de
        // arriba sí se siembran en producción.
        if (app()->isProduction()) {
            $this->command?->warn('Producción: se omiten los seeders de demo/prueba (SamsaraTest*, SuperAdmin).');

            return;
        }

        // Single dev tenant (ServiExpress JC) + panic_button→incident ruleset.
        $this->call(SamsaraTestSeeder::class);
        $this->call(SamsaraTestDecisionRulesSeeder::class);

        // Operador SaaS de desarrollo (super-admin GLOBAL, sin membresía en el
        // tenant de prueba): el admin de ServiExpress representa al cliente y
        // sólo ve su empresa. Va después de SamsaraTestSeeder para poder
        // retirar el rol a un admin promovido por versiones anteriores.
        $this->call(SuperAdminSeeder::class);
    }
}
