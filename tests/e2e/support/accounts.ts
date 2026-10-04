import path from 'node:path';
import { AUTH_DIR } from './env';

/** Cuentas de SamsaraTestSeeder / SuperAdminSeeder (contraseña `password`). */
export const TEAM = 'serviexpress-jc';

export const PASSWORD = 'password';

export const ACCOUNTS = {
    monitor: 'monitor@serviexpress.test',
    admin: 'admin@serviexpress.test',
    superAdmin: 'superadmin@sam.test',
} as const;

export type Role = keyof typeof ACCOUNTS;

export function storageStateFor(role: Role): string {
    return path.join(AUTH_DIR, `${role}.json`);
}

/** Secreto TOTP del super-admin (E2eSeeder::SUPER_ADMIN_TOTP_SECRET). */
export const SUPER_ADMIN_TOTP_SECRET = 'JBSWY3DPEHPK3PXP';
