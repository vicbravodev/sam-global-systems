<?php

namespace Tests\Feature\Domains\Access;

use App\Domains\Access\Actions\SyncRolePermissions;
use App\Domains\Access\Events\PermissionsSynced;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SyncRolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_role_permissions_updates_pivot(): void
    {
        $role = Role::factory()->create();

        $viewPerm = Permission::factory()->create(['code' => 'incidents.view', 'module' => 'incidents']);
        $managePerm = Permission::factory()->create(['code' => 'incidents.manage', 'module' => 'incidents']);
        $resolvePerm = Permission::factory()->create(['code' => 'incidents.resolve', 'module' => 'incidents']);

        $role->permissions()->attach([$viewPerm->id]);

        Event::fake([PermissionsSynced::class]);

        app(SyncRolePermissions::class)->execute($role, ['incidents.manage', 'incidents.resolve']);

        $attachedCodes = $role->permissions()->pluck('code')->sort()->values()->all();

        $this->assertEquals(
            ['incidents.manage', 'incidents.resolve'],
            $attachedCodes,
            'Sync should detach incidents.view and attach incidents.manage + incidents.resolve',
        );
    }

    public function test_sync_dispatches_permissions_synced_event(): void
    {
        Event::fake([PermissionsSynced::class]);

        $role = Role::factory()->create();
        Permission::factory()->create(['code' => 'assets.view', 'module' => 'assets']);
        Permission::factory()->create(['code' => 'assets.manage', 'module' => 'assets']);

        $codes = ['assets.view', 'assets.manage'];

        app(SyncRolePermissions::class)->execute($role, $codes);

        Event::assertDispatched(PermissionsSynced::class, function (PermissionsSynced $event) use ($role, $codes) {
            return $event->role->id === $role->id
                && $event->permissionCodes === $codes;
        });
    }
}
