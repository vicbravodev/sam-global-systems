<?php

namespace Tests\Feature\Seeders;

use App\Domains\Access\Models\Role;
use App\Domains\Analytics\Models\MetricDefinition;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SamsaraTestSeeder;
use Database\Seeders\Showcase\ShowcaseSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * En producción el DatabaseSeeder siembra los catálogos (roles, planes,
 * medidores, normalización, …) pero NUNCA las cuentas de demo/prueba con
 * contraseña conocida ni el super-admin de desarrollo.
 */
class ProductionSeedingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  class-string<Seeder>  $class
     */
    private function runSeeder(string $class): void
    {
        Model::unguarded(fn () => $this->app->make($class)->setContainer($this->app)->__invoke());
    }

    private function inProduction(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertTrue($this->app->isProduction());
    }

    public function test_database_seeder_in_production_seeds_catalogs_but_no_demo_accounts(): void
    {
        $this->inProduction();

        $this->runSeeder(DatabaseSeeder::class);

        $this->assertTrue(Role::where('code', 'tenant_admin')->exists());
        $this->assertGreaterThan(0, Plan::count());

        $this->assertSame(0, User::count(), 'Producción no debe tener usuarios sembrados.');
        $this->assertDatabaseMissing('users', ['email' => SuperAdminSeeder::SUPER_ADMIN_EMAIL]);
        $this->assertDatabaseMissing('teams', ['slug' => 'serviexpress-jc']);
    }

    public function test_demo_seeders_refuse_to_run_explicitly_in_production(): void
    {
        $this->inProduction();

        foreach ([SamsaraTestSeeder::class, SuperAdminSeeder::class, ShowcaseSeeder::class] as $seeder) {
            $this->runSeeder($seeder);
        }

        $this->assertSame(0, User::count());
        $this->assertDatabaseMissing('teams', ['slug' => 'serviexpress-jc']);
    }

    public function test_database_seeder_outside_production_still_seeds_the_dev_tenant(): void
    {
        $this->runSeeder(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['email' => SuperAdminSeeder::SUPER_ADMIN_EMAIL, 'global_role' => 'super_admin']);
    }

    public function test_database_seeder_in_production_seeds_every_meter_and_analytics_catalog_the_code_uses(): void
    {
        $this->inProduction();

        $this->runSeeder(DatabaseSeeder::class);

        // Códigos que RecordUsageEvent resuelve con firstOrFail(): si falta uno,
        // la acción que factura (reporte, workflow, acción, Copilot…) revienta.
        foreach (['generated_reports', 'automation_actions', 'incident_workflows', 'copilot_queries', 'ingested_events', 'media_requests', 'voice_calls', 'ai_calls', 'otp_sms_sent', 'monitored_assets'] as $meter) {
            $this->assertTrue(UsageMeter::where('code', $meter)->exists(), "Falta el medidor {$meter}.");
        }

        $this->assertTrue(MetricDefinition::where('code', 'incidents_total')->where('is_active', true)->exists());
        $this->assertGreaterThanOrEqual(9, MetricDefinition::where('is_active', true)->count());
        $this->assertGreaterThan(0, ReportDefinition::whereNull('team_id')->where('is_active', true)->count());

        // Idempotente: una segunda pasada no duplica catálogos.
        $metrics = MetricDefinition::count();
        $reports = ReportDefinition::count();
        $this->runSeeder(DatabaseSeeder::class);
        $this->assertSame($metrics, MetricDefinition::count());
        $this->assertSame($reports, ReportDefinition::count());
    }
}
