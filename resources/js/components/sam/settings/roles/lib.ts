import { teamRoleLabel } from '@/lib/labels';
import type { RolePermissionOption, RoleRow } from '@/types/sam';

export type PermissionGroups = Record<string, RolePermissionOption[]>;

/** Etiqueta de reserva cuando el miembro aún no tiene rol de acceso. */
export function fallbackRoleLabel(legacyRole: string | null): string {
    return legacyRole
        ? `${teamRoleLabel(legacyRole)} (sin rol de acceso)`
        : 'Sin rol';
}

/** Nombre legible de cada módulo de permisos (clave = Permission.module). */
const MODULE_LABELS: Record<string, string> = {
    ai: 'Inteligencia artificial',
    assets: 'Flota',
    audit: 'Auditoría',
    automation: 'Automatizaciones',
    config: 'Configuración',
    context: 'Contexto operativo',
    copilot: 'SAM Copilot',
    decisions: 'Reglas y decisiones',
    drivers: 'Conductores',
    geofences: 'Zonas',
    incidents: 'Incidentes',
    integrations: 'Integraciones',
    notifications: 'Avisos',
    reports: 'Reportes y analítica',
    tenancy: 'Empresa y facturación',
    users: 'Personas y roles',
};

export function moduleLabel(module: string): string {
    return MODULE_LABELS[module] ?? module;
}

/**
 * Los roles predefinidos vienen del catálogo de plataforma con la palabra
 * "tenant"; para quien opera, su cuenta es "la empresa".
 */
function dejargon(text: string): string {
    return text
        .replace(/\bdel tenant\b/gi, 'de la empresa')
        .replace(/\bel tenant\b/gi, 'la empresa')
        .replace(/\btenant\b/gi, 'empresa');
}

export function humanizeRole(role: RoleRow): RoleRow {
    return role.isSystem
        ? {
              ...role,
              name: dejargon(role.name),
              description: role.description
                  ? dejargon(role.description)
                  : role.description,
          }
        : role;
}

/** Deriva un identificador interno (snake_case) a partir del nombre. */
export function slugifyCode(name: string): string {
    return name
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 50);
}
