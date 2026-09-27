<?php

namespace Tests\Feature\Seeders;

use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Models\Plan;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\SamsaraTestSeeder;
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

        foreach ([SamsaraTestSeeder::class, SuperAdminSeeder::class, DemoSeeder::class] as $seeder) {
            $this->runSeeder($seeder);
        }

        $this->assertSame(0, User::count());
    }

    public function test_database_seeder_outside_production_still_seeds_the_dev_tenant(): void
    {
        $this->runSeeder(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['email' => SuperAdminSeeder::SUPER_ADMIN_EMAIL, 'global_role' => 'super_admin']);
    }
}
