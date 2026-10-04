import { copyFileSync, renameSync, rmSync } from 'node:fs';
import { test as base, expect } from '@playwright/test';
import { artisan, DATABASE, DATABASE_SNAPSHOT } from './env';

/**
 * Cada test arranca con la base recién sembrada: los workers corren en serie
 * y comparten un servidor, así que restaurar el archivo SQLite es suficiente
 * (las sesiones viven en archivos, los ids sembrados no cambian). El reemplazo
 * es atómico (copia + rename): una petición rezagada del test anterior sigue
 * con su archivo y no corrompe el nuevo. La caché también se vacía: guarda el
 * throttle de login (5/min por correo e IP) y las ventanas de deduplicación.
 */
export const test = base.extend<{ freshDatabase: void }>({
    freshDatabase: [
        // eslint-disable-next-line no-empty-pattern
        async ({}, use) => {
            const staging = `${DATABASE}.next`;

            copyFileSync(DATABASE_SNAPSHOT, staging);
            rmSync(`${DATABASE}-journal`, { force: true });
            renameSync(staging, DATABASE);
            artisan('cache:clear');
            await use();
        },
        { auto: true },
    ],
});

export { expect };
