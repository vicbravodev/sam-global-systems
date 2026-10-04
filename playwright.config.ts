import { defineConfig, devices } from '@playwright/test';
import { BASE_URL, E2E_ENV, PORT } from './tests/e2e/support/env';

/**
 * E2E de los flujos críticos contra la app real (`php artisan serve` sobre el
 * build de Vite). Requiere `npm run build` antes. Convenciones en
 * resources/js/CLAUDE.md (sección E2E).
 */
export default defineConfig({
    testDir: 'tests/e2e',
    globalSetup: './tests/e2e/global-setup.ts',
    // Un solo servidor y una sola base SQLite: en serie.
    workers: 1,
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI
        ? [['github'], ['html', { open: 'never' }]]
        : 'list',
    use: {
        baseURL: BASE_URL,
        locale: 'es-MX',
        timezoneId: 'America/Mexico_City',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
            dependencies: ['setup'],
        },
    ],
    webServer: {
        command: `php artisan serve --host=127.0.0.1 --port=${PORT}`,
        url: `${BASE_URL}/up`,
        env: E2E_ENV,
        reuseExistingServer: !process.env.CI,
        timeout: 60_000,
    },
});
