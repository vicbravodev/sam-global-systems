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
        ['code' => 'super_admin', 'name' => 'Super Administrador', 'scope' => 'global', 'description' => 'Acceso total al sistema, omite todas las verificaciones de tenant'],
        ['code' => 'tenant_admin', 'name' => 'Administrador del tenant', 'scope' => 'tenant', 'description' => 'Gestión completa del tenant, incluyendo facturación y usuarios'],
        ['code' => 'supervisor', 'name' => 'Supervisor', 'scope' => 'tenant', 'description' => 'Supervisión operativa: incidentes, activos, conductores, reportes'],
        ['code' => 'monitorista', 'name' => 'Monitorista', 'scope' => 'tenant', 'description' => 'Monitoreo en tiempo real: ver/gestionar/resolver incidentes, ver activos'],
        ['code' => 'analyst', 'name' => 'Analista', 'scope' => 'tenant', 'description' => 'Analítica de solo lectura: reportes, análisis de IA, registros de auditoría'],
        ['code' => 'billing_manager', 'name' => 'Gestor de facturación', 'scope' => 'tenant', 'description' => 'Gestión de facturación y suscripción'],
        ['code' => 'viewer', 'name' => 'Observador', 'scope' => 'tenant', 'description' => 'Acceso de solo lectura a todos los módulos del tenant'],
    ];

    /**
     * @var array<array{code: string, name: string, module: string, description: string}>
     */
    private const PERMISSIONS = [
        ['code' => 'tenancy.manage', 'name' => 'Gestionar tenant', 'module' => 'tenancy', 'description' => 'Gestionar ajustes, marca y características del tenant'],
        ['code' => 'tenancy.billing.view', 'name' => 'Ver facturación', 'module' => 'tenancy', 'description' => 'Ver panel de facturación, facturas y uso'],
        ['code' => 'tenancy.billing.manage', 'name' => 'Gestionar facturación', 'module' => 'tenancy', 'description' => 'Gestionar suscripciones y métodos de pago'],
        ['code' => 'integrations.view', 'name' => 'Ver integraciones', 'module' => 'integrations', 'description' => 'Ver integraciones del tenant y sus proveedores'],
        ['code' => 'integrations.manage', 'name' => 'Gestionar integraciones', 'module' => 'integrations', 'description' => 'Conectar, configurar, probar y desconectar integraciones'],
        ['code' => 'incidents.view', 'name' => 'Ver incidentes', 'module' => 'incidents', 'description' => 'Ver incidentes y sus detalles'],
        ['code' => 'incidents.manage', 'name' => 'Gestionar incidentes', 'module' => 'incidents', 'description' => 'Crear y actualizar incidentes'],
        ['code' => 'incidents.resolve', 'name' => 'Resolver incidentes', 'module' => 'incidents', 'description' => 'Resolver incidentes abiertos'],
        ['code' => 'incidents.close', 'name' => 'Cerrar incidentes', 'module' => 'incidents', 'description' => 'Cerrar incidentes resueltos'],
        ['code' => 'assets.view', 'name' => 'Ver activos', 'module' => 'assets', 'description' => 'Ver activos y su estado'],
        ['code' => 'assets.manage', 'name' => 'Gestionar activos', 'module' => 'assets', 'description' => 'Crear, actualizar y eliminar activos'],
        ['code' => 'drivers.view', 'name' => 'Ver conductores', 'module' => 'drivers', 'description' => 'Ver perfiles y asignaciones de conductores'],
        ['code' => 'drivers.manage', 'name' => 'Gestionar conductores', 'module' => 'drivers', 'description' => 'Crear, actualizar y eliminar conductores'],
        ['code' => 'context.view', 'name' => 'Ver contexto del evento', 'module' => 'context', 'description' => 'Ver snapshots y perfiles de contexto del evento'],
        ['code' => 'geofences.view', 'name' => 'Ver geocercas', 'module' => 'geofences', 'description' => 'Ver geocercas del equipo'],
        ['code' => 'geofences.manage', 'name' => 'Gestionar geocercas', 'module' => 'geofences', 'description' => 'Crear, actualizar y eliminar geocercas'],
        ['code' => 'reports.view', 'name' => 'Ver reportes', 'module' => 'reports', 'description' => 'Ver reportes generados'],
        ['code' => 'reports.export', 'name' => 'Exportar reportes', 'module' => 'reports', 'description' => 'Exportar reportes a archivo'],
        ['code' => 'ai.analysis.view', 'name' => 'Ver análisis de IA', 'module' => 'ai', 'description' => 'Ver resultados del análisis de IA'],
        ['code' => 'ai.analysis.execute', 'name' => 'Ejecutar análisis de IA', 'module' => 'ai', 'description' => 'Disparar el análisis de IA'],
        ['code' => 'config.view', 'name' => 'Ver configuración', 'module' => 'config', 'description' => 'Ver la configuración del tenant'],
        ['code' => 'config.manage', 'name' => 'Gestionar configuración', 'module' => 'config', 'description' => 'Modificar la configuración del tenant'],
        ['code' => 'users.view', 'name' => 'Ver usuarios', 'module' => 'users', 'description' => 'Ver miembros del equipo'],
        ['code' => 'users.manage', 'name' => 'Gestionar usuarios', 'module' => 'users', 'description' => 'Actualizar roles y datos de los miembros'],
        ['code' => 'users.invite', 'name' => 'Invitar usuarios', 'module' => 'users', 'description' => 'Invitar nuevos miembros al equipo'],
        ['code' => 'audit.view', 'name' => 'Ver auditoría', 'module' => 'audit', 'description' => 'Ver registros de auditoría'],
        ['code' => 'decisions.view', 'name' => 'Ver decisiones', 'module' => 'decisions', 'description' => 'Ver decisiones y sus trazas'],
        ['code' => 'decisions.override', 'name' => 'Anular decisiones', 'module' => 'decisions', 'description' => 'Anular manualmente decisiones automatizadas'],
        ['code' => 'decisions.rules.manage', 'name' => 'Gestionar reglas de decisión', 'module' => 'decisions', 'description' => 'Crear, actualizar o desactivar reglas de decisión'],
        ['code' => 'decisions.escalation.manage', 'name' => 'Gestionar políticas de escalación', 'module' => 'decisions', 'description' => 'Crear o actualizar políticas de escalación'],
        ['code' => 'notifications.view', 'name' => 'Ver notificaciones', 'module' => 'notifications', 'description' => 'Ver notificaciones, plantillas y canales'],
        ['code' => 'notifications.send', 'name' => 'Enviar notificaciones', 'module' => 'notifications', 'description' => 'Enviar notificaciones manuales'],
        ['code' => 'notifications.manage', 'name' => 'Gestionar notificaciones', 'module' => 'notifications', 'description' => 'Gestionar plantillas y canales de notificación'],
        ['code' => 'automation.view', 'name' => 'Ver automatización', 'module' => 'automation', 'description' => 'Ver flujos de automatización y sus ejecuciones'],
        ['code' => 'automation.manage', 'name' => 'Gestionar automatización', 'module' => 'automation', 'description' => 'Crear, actualizar y eliminar flujos y plantillas de automatización'],
        ['code' => 'automation.execute', 'name' => 'Ejecutar automatización', 'module' => 'automation', 'description' => 'Disparar flujos manualmente y gestionar ejecuciones de acciones individuales'],
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
            'integrations.view', 'integrations.manage',
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
            'automation.view', 'automation.manage', 'automation.execute',
        ],
        'supervisor' => [
            'integrations.view', 'integrations.manage',
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
            'automation.view', 'automation.execute',
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
            'integrations.view',
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
            'automation.view',
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
