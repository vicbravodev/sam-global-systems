import { copyFileSync, rmSync, writeFileSync } from 'node:fs';
import { artisan, DATABASE, DATABASE_SNAPSHOT } from './support/env';

/**
 * Base E2E desde cero (migraciones + E2eSeeder) y su copia limpia. Limpia la
 * caché: guarda el throttle de login (5/min por correo e IP) entre corridas.
 */
export default function globalSetup(): void {
    artisan('cache:clear');
    rmSync(DATABASE, { force: true });
    writeFileSync(DATABASE, '');
    artisan('migrate:fresh', '--seed', '--seeder=E2eSeeder', '--force');
    copyFileSync(DATABASE, DATABASE_SNAPSHOT);
}
