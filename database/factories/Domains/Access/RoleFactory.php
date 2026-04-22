<?php

namespace Database\Factories\Domains\Access;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
            'code' => fake()->unique()->slug(2),
            'description' => fake()->sentence(),
            'scope' => RoleScope::Tenant,
            'is_system' => false,
        ];
    }

    public function global(): static
    {
        return $this->state(fn () => [
            'scope' => RoleScope::Global,
        ]);
    }

    public function system(): static
    {
        return $this->state(fn () => [
            'is_system' => true,
        ]);
    }

    public function tenantAdmin(): static
    {
        return $this->system()->state(fn () => [
            'name' => 'Tenant Admin',
            'code' => 'tenant_admin',
            'scope' => RoleScope::Tenant,
        ]);
    }

    public function supervisor(): static
    {
        return $this->system()->state(fn () => [
            'name' => 'Supervisor',
            'code' => 'supervisor',
            'scope' => RoleScope::Tenant,
        ]);
    }

    public function monitorista(): static
    {
        return $this->system()->state(fn () => [
            'name' => 'Monitorista',
            'code' => 'monitorista',
            'scope' => RoleScope::Tenant,
        ]);
    }

    public function analyst(): static
    {
        return $this->system()->state(fn () => [
            'name' => 'Analyst',
            'code' => 'analyst',
            'scope' => RoleScope::Tenant,
        ]);
    }

    public function billingManager(): static
    {
        return $this->system()->state(fn () => [
            'name' => 'Billing Manager',
            'code' => 'billing_manager',
            'scope' => RoleScope::Tenant,
        ]);
    }

    public function viewer(): static
    {
        return $this->system()->state(fn () => [
            'name' => 'Viewer',
            'code' => 'viewer',
            'scope' => RoleScope::Tenant,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->system()->global()->state(fn () => [
            'name' => 'Super Admin',
            'code' => 'super_admin',
        ]);
    }
}
