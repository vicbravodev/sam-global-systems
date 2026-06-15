<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_the_dev_admin_as_super_admin_and_grants_admin_access(): void
    {
        $admin = User::factory()->create([
            'email' => SuperAdminSeeder::SUPER_ADMIN_EMAIL,
            'global_role' => null,
        ]);

        $this->seed(SuperAdminSeeder::class);

        $admin->refresh();
        $this->assertSame('super_admin', $admin->global_role);
        $this->assertTrue($admin->isSuperAdmin());

        $this->actingAs($admin)
            ->get(route('admin.tenants.index'))
            ->assertOk();
    }

    public function test_it_is_idempotent(): void
    {
        $admin = User::factory()->create([
            'email' => SuperAdminSeeder::SUPER_ADMIN_EMAIL,
            'global_role' => null,
        ]);

        $this->seed(SuperAdminSeeder::class);
        $this->seed(SuperAdminSeeder::class);

        $this->assertSame('super_admin', $admin->fresh()->global_role);
    }

    public function test_it_does_nothing_when_the_dev_admin_is_absent(): void
    {
        $this->seed(SuperAdminSeeder::class);

        $this->assertDatabaseMissing('users', [
            'email' => SuperAdminSeeder::SUPER_ADMIN_EMAIL,
        ]);
    }
}
