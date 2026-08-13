<?php

namespace Tests\Feature\Domains\Access;

use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RoleSpanishTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_are_seeded_in_spanish(): void
    {
        $this->seed(AccessSeeder::class);

        $this->assertSame('Analista', DB::table('roles')->where('code', 'analyst')->value('name'));
        $this->assertSame('Gestor de facturación', DB::table('roles')->where('code', 'billing_manager')->value('name'));
        $this->assertSame(
            'Gestión completa del tenant, incluyendo facturación y usuarios',
            DB::table('roles')->where('code', 'tenant_admin')->value('description'),
        );
    }

    public function test_permissions_are_seeded_in_spanish(): void
    {
        $this->seed(AccessSeeder::class);

        $this->assertSame('Ver incidentes', DB::table('permissions')->where('code', 'incidents.view')->value('name'));
        $this->assertSame('Gestionar activos', DB::table('permissions')->where('code', 'assets.manage')->value('name'));
        $this->assertSame('Invitar usuarios', DB::table('permissions')->where('code', 'users.invite')->value('name'));
    }
}
