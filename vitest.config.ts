import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

// Fechas deterministas: el producto formatea en es-MX y CDMX no tiene horario
// de verano, así que los tests no cambian con la máquina ni con la época.
process.env.TZ = 'America/Mexico_City';

/**
 * Tests de la lógica pura del frontend (lib/, helpers y hooks sin UI). No
 * reutiliza vite.config.ts: el plugin de Laravel, Wayfinder y Tailwind sólo
 * sirven para el build.
 */
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: ['resources/js/**/*.test.{ts,tsx}'],
        environment: 'jsdom',
        setupFiles: ['resources/js/test-setup.ts'],
        restoreMocks: true,
        unstubGlobals: true,
    },
});
