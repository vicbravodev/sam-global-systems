<?php

namespace Database\Factories\Domains\Access;

use App\Domains\Access\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    public function definition(): array
    {
        $module = fake()->randomElement(['incidents', 'assets', 'drivers', 'reports', 'ai', 'tenancy', 'config', 'users', 'audit']);
        $action = fake()->randomElement(['view', 'manage', 'create', 'export']);

        return [
            'code' => "{$module}.{$action}.".fake()->unique()->numerify('##'),
            'name' => ucfirst($action).' '.ucfirst($module),
            'description' => fake()->sentence(),
            'module' => $module,
        ];
    }

    public function forModule(string $module): static
    {
        return $this->state(fn () => [
            'module' => $module,
        ]);
    }
}
