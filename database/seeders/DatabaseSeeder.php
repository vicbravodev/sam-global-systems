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
        // incident_workflows / automation_actions: sin ellos PlanSeeder omite
        // el límite de workflows y RecordUsageEvent revienta (firstOrFail).
        $this->call(AutomationMeterSeeder::class);
        $this->call(AnalyticsMeterSeeder::class);
        // PlanSeeder must run after every *MeterSeeder so meter codes resolve.
        $this->call(PlanSeeder::class);

        // Catálogos de Analítica: sin métricas activas los KPIs diarios no
        // calculan nada; los reportes globales los ve todo tenant.
        $this->call(MetricDefinitionSeeder::class);
        $this->call(ReportDefinitionSeeder::class);

        // Samsara mapping rules so replayed/live webhook events normalize.
        $this->call(NormalizationSeeder::class);

        // Todo lo de abajo es de desarrollo/prueba (cuentas con contraseña
        // conocida y un super-admin): nunca en producción. En producción la
        // ÚNICA cuenta sembrada es el operador SaaS de SAM_SUPER_ADMIN_*; los
        // clientes se dan de alta desde su consola (/admin/tenants).
        if (app()->isProduction()) {
            $this->call(PlatformSuperAdminSeeder::class);
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

        $this->command?->info('Para poblar todas las pantallas con 90 días simulados: php artisan sam:showcase (ver Showcase/ShowcaseSeeder).');
    }
}
