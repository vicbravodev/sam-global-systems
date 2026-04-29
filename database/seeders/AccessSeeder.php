<?php

namespace Database\Seeders;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use Illuminate\Database\Seeder;

class AccessSeeder extends Seeder
{
    /**
     * @var array<array{code: string, name: string, scope: string, description: string}>
     */
    private const ROLES = [
        ['code' => 'super_admin', 'name' => 'Super Admin', 'scope' => 'global', 'description' => 'Full system access, bypasses all tenant checks'],
        ['code' => 'tenant_admin', 'name' => 'Tenant Admin', 'scope' => 'tenant', 'description' => 'Full tenant management including billing and users'],
        ['code' => 'supervisor', 'name' => 'Supervisor', 'scope' => 'tenant', 'description' => 'Operational oversight: incidents, assets, drivers, reports'],
        ['code' => 'monitorista', 'name' => 'Monitorista', 'scope' => 'tenant', 'description' => 'Real-time monitoring: incidents view/manage/resolve, assets view'],
        ['code' => 'analyst', 'name' => 'Analyst', 'scope' => 'tenant', 'description' => 'Read-only analytics: reports, AI analysis, audit logs'],
        ['code' => 'billing_manager', 'name' => 'Billing Manager', 'scope' => 'tenant', 'description' => 'Billing and subscription management'],
        ['code' => 'viewer', 'name' => 'Viewer', 'scope' => 'tenant', 'description' => 'Read-only access across all tenant modules'],
    ];

    /**
     * @var array<array{code: string, name: string, module: string, description: string}>
     */
    private const PERMISSIONS = [
        ['code' => 'tenancy.manage', 'name' => 'Manage Tenancy', 'module' => 'tenancy', 'description' => 'Manage tenant settings, branding, features'],
        ['code' => 'tenancy.billing.view', 'name' => 'View Billing', 'module' => 'tenancy', 'description' => 'View billing dashboard, invoices, usage'],
        ['code' => 'tenancy.billing.manage', 'name' => 'Manage Billing', 'module' => 'tenancy', 'description' => 'Manage subscriptions, payment methods'],
        ['code' => 'incidents.view', 'name' => 'View Incidents', 'module' => 'incidents', 'description' => 'View incidents and their details'],
        ['code' => 'incidents.manage', 'name' => 'Manage Incidents', 'module' => 'incidents', 'description' => 'Create and update incidents'],
        ['code' => 'incidents.resolve', 'name' => 'Resolve Incidents', 'module' => 'incidents', 'description' => 'Resolve open incidents'],
        ['code' => 'incidents.close', 'name' => 'Close Incidents', 'module' => 'incidents', 'description' => 'Close resolved incidents'],
        ['code' => 'assets.view', 'name' => 'View Assets', 'module' => 'assets', 'description' => 'View assets and their status'],
        ['code' => 'assets.manage', 'name' => 'Manage Assets', 'module' => 'assets', 'description' => 'Create, update, delete assets'],
        ['code' => 'drivers.view', 'name' => 'View Drivers', 'module' => 'drivers', 'description' => 'View driver profiles and assignments'],
        ['code' => 'drivers.manage', 'name' => 'Manage Drivers', 'module' => 'drivers', 'description' => 'Create, update, delete drivers'],
        ['code' => 'context.view', 'name' => 'View Event Context', 'module' => 'context', 'description' => 'View event context snapshots and profiles'],
        ['code' => 'geofences.view', 'name' => 'View Geofences', 'module' => 'geofences', 'description' => 'View team geofences'],
        ['code' => 'geofences.manage', 'name' => 'Manage Geofences', 'module' => 'geofences', 'description' => 'Create, update, delete geofences'],
        ['code' => 'reports.view', 'name' => 'View Reports', 'module' => 'reports', 'description' => 'View generated reports'],
        ['code' => 'reports.export', 'name' => 'Export Reports', 'module' => 'reports', 'description' => 'Export reports to file'],
        ['code' => 'ai.analysis.view', 'name' => 'View AI Analysis', 'module' => 'ai', 'description' => 'View AI analysis results'],
        ['code' => 'ai.analysis.execute', 'name' => 'Execute AI Analysis', 'module' => 'ai', 'description' => 'Trigger AI analysis'],
        ['code' => 'config.view', 'name' => 'View Config', 'module' => 'config', 'description' => 'View tenant configuration'],
        ['code' => 'config.manage', 'name' => 'Manage Config', 'module' => 'config', 'description' => 'Modify tenant configuration'],
        ['code' => 'users.view', 'name' => 'View Users', 'module' => 'users', 'description' => 'View team members'],
        ['code' => 'users.manage', 'name' => 'Manage Users', 'module' => 'users', 'description' => 'Update member roles and details'],
        ['code' => 'users.invite', 'name' => 'Invite Users', 'module' => 'users', 'description' => 'Invite new members to the team'],
        ['code' => 'audit.view', 'name' => 'View Audit', 'module' => 'audit', 'description' => 'View audit logs'],
        ['code' => 'decisions.view', 'name' => 'View Decisions', 'module' => 'decisions', 'description' => 'View decisions and traces'],
        ['code' => 'decisions.override', 'name' => 'Override Decisions', 'module' => 'decisions', 'description' => 'Manually override automated decisions'],
        ['code' => 'decisions.rules.manage', 'name' => 'Manage Decision Rules', 'module' => 'decisions', 'description' => 'Create, update or deactivate decision rules'],
        ['code' => 'decisions.escalation.manage', 'name' => 'Manage Escalation Policies', 'module' => 'decisions', 'description' => 'Create or update escalation policies'],
        ['code' => 'notifications.view', 'name' => 'View Notifications', 'module' => 'notifications', 'description' => 'View notifications, templates and channels'],
        ['code' => 'notifications.send', 'name' => 'Send Notifications', 'module' => 'notifications', 'description' => 'Send manual notifications'],
        ['code' => 'notifications.manage', 'name' => 'Manage Notifications', 'module' => 'notifications', 'description' => 'Manage notification templates and channels'],
    ];

    /**
     * Maps role code to an array of permission codes.
     * super_admin is handled at gate level and does not need explicit assignments.
     *
     * @var array<string, array<string>>
     */
    private const ROLE_PERMISSIONS = [
        'tenant_admin' => [
            'tenancy.manage', 'tenancy.billing.view', 'tenancy.billing.manage',
            'incidents.view', 'incidents.manage', 'incidents.resolve', 'incidents.close',
            'assets.view', 'assets.manage',
            'drivers.view', 'drivers.manage',
            'context.view',
            'geofences.view', 'geofences.manage',
            'reports.view', 'reports.export',
            'ai.analysis.view', 'ai.analysis.execute',
            'config.view', 'config.manage',
            'users.view', 'users.manage', 'users.invite',
            'audit.view',
            'decisions.view', 'decisions.override', 'decisions.rules.manage', 'decisions.escalation.manage',
            'notifications.view', 'notifications.send', 'notifications.manage',
        ],
        'supervisor' => [
            'incidents.view', 'incidents.manage', 'incidents.resolve', 'incidents.close',
            'assets.view', 'assets.manage',
            'drivers.view', 'drivers.manage',
            'context.view',
            'geofences.view', 'geofences.manage',
            'reports.view', 'reports.export',
            'ai.analysis.view',
            'config.view',
            'users.view',
            'audit.view',
            'decisions.view', 'decisions.override', 'decisions.rules.manage', 'decisions.escalation.manage',
            'notifications.view', 'notifications.send',
        ],
        'monitorista' => [
            'incidents.view', 'incidents.manage', 'incidents.resolve',
            'assets.view',
            'drivers.view',
            'context.view',
            'geofences.view',
            'decisions.view',
            'notifications.view',
        ],
        'analyst' => [
            'reports.view', 'reports.export',
            'ai.analysis.view', 'ai.analysis.execute',
            'audit.view',
            'assets.view',
            'incidents.view',
            'context.view',
            'decisions.view',
        ],
        'billing_manager' => [
            'tenancy.billing.view', 'tenancy.billing.manage',
            'tenancy.manage',
        ],
        'viewer' => [
            'tenancy.billing.view',
            'incidents.view',
            'assets.view',
            'drivers.view',
            'context.view',
            'geofences.view',
            'reports.view',
            'ai.analysis.view',
            'config.view',
            'users.view',
            'audit.view',
            'decisions.view',
            'notifications.view',
        ],
    ];

    public function run(): void
    {
        $this->seedRoles();
        $this->seedPermissions();
        $this->seedRolePermissions();
    }

    private function seedRoles(): void
    {
        foreach (self::ROLES as $roleData) {
            Role::updateOrCreate(
                ['code' => $roleData['code']],
                [
                    'name' => $roleData['name'],
                    'scope' => $roleData['scope'],
                    'description' => $roleData['description'],
                    'is_system' => true,
                ],
            );
        }
    }

    private function seedPermissions(): void
    {
        foreach (self::PERMISSIONS as $permData) {
            Permission::updateOrCreate(
                ['code' => $permData['code']],
                [
                    'name' => $permData['name'],
                    'module' => $permData['module'],
                    'description' => $permData['description'],
                ],
            );
        }
    }

    private function seedRolePermissions(): void
    {
        foreach (self::ROLE_PERMISSIONS as $roleCode => $permissionCodes) {
            $role = Role::where('code', $roleCode)->first();

            if (! $role) {
                continue;
            }

            $permissionIds = Permission::whereIn('code', $permissionCodes)->pluck('id');
            $role->permissions()->sync($permissionIds);
        }
    }
}
