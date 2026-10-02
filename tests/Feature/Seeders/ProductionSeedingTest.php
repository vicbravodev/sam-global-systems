<?php

namespace Tests\Feature\Seeders;

use App\Domains\Access\Models\Role;
use App\Domains\Analytics\Models\MetricDefinition;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SamsaraTestSeeder;
use Database\Seeders\Showcase\ShowcaseSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * En producción el DatabaseSeeder siembra los catálogos (roles, planes,
 * medidores, normalización, …) pero NUNCA las cuentas de demo/prueba con
 * contraseña conocida ni el super-admin de desarrollo.
 */
class ProductionSeedingTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private const string STRONG_PASSWORD = 'Sam-Operador-2026!x';

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
        // Password::defaults() de producción incluye uncompromised() (HIBP).
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
    }

    private function configureSuperAdmin(?string $email = 'victor@sam.example', ?string $password = self::STRONG_PASSWORD): void
    {
        config(['auth.super_admin' => ['email' => $email, 'name' => 'Victor Bravo', 'password' => $password]]);
    }

    public function test_database_seeder_in_production_creates_only_the_configured_super_admin(): void
    {
        $this->inProduction();
        $this->configureSuperAdmin();

        $this->runSeeder(DatabaseSeeder::class);

        $this->assertSame(1, User::count(), 'Producción sólo debe sembrar la cuenta del operador.');
        $operator = User::sole();
        $this->assertSame('victor@sam.example', $operator->email);
        $this->assertTrue($operator->isSuperAdmin());
        $this->assertNotNull($operator->email_verified_at);
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $operator->password));

        // Su único team es el personal (para volver tras impersonar): ningún tenant cliente.
        $this->assertSame(0, Team::where('is_personal', false)->count());
        $this->assertNotNull($operator->personalTeam());
        $this->assertSame($operator->personalTeam()?->id, $operator->current_team_id);

        $this->assertSystemLogged('tenancy.super_admin.bootstrapped', fn (array $e): bool => $e['result']['created'] === true);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_reseeding_production_never_changes_the_operator_password_nor_duplicates_it(): void
    {
        $this->inProduction();
        $this->configureSuperAdmin();
        $this->runSeeder(DatabaseSeeder::class);

        $this->configureSuperAdmin(password: 'Otra-Contrasena-2026!y');
        $this->runSeeder(DatabaseSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertSame(1, Team::count());
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, User::sole()->password));
    }

    public function test_production_seed_without_password_does_not_create_the_operator(): void
    {
        $this->inProduction();
        $this->configureSuperAdmin(password: null);

        $this->runSeeder(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
    }

    public function test_production_seed_rejects_a_weak_operator_password(): void
    {
        $this->inProduction();
        $this->configureSuperAdmin(password: 'password');

        $this->expectException(RuntimeException::class);

        $this->runSeeder(DatabaseSeeder::class);
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
