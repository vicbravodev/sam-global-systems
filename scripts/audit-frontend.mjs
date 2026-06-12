#!/usr/bin/env node
/**
 * Auditoría visual del frontend (F4.2): recorre las páginas clave con
 * Playwright y guarda screenshots por viewport y tema en
 * `storage/app/frontend-audit/{fecha}/`.
 *
 * Uso:
 *   php artisan serve --port=8088 &        # con datos seeded (DemoSeeder)
 *   node scripts/audit-frontend.mjs [--base=http://127.0.0.1:8088]
 *       [--email=admin@sam.test] [--password=password]
 *
 * Requiere Playwright (npx playwright install chromium si falta el browser).
 */
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const args = Object.fromEntries(
    process.argv
        .slice(2)
        .filter((a) => a.startsWith('--'))
        .map((a) => a.replace(/^--/, '').split('=')),
);

const BASE = args.base ?? 'http://127.0.0.1:8088';
const EMAIL = args.email ?? 'admin@sam.test';
const PASSWORD = args.password ?? 'password';

const VIEWPORTS = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'mobile', width: 390, height: 844 },
];

const THEMES = ['dark', 'light'];

// Rutas relativas al slug del tenant ({slug} se sustituye tras el login).
const PAGES = [
    ['dashboard', '/{slug}/dashboard'],
    ['incidents', '/{slug}/incidents'],
    ['events', '/{slug}/events'],
    ['assets', '/{slug}/assets'],
    ['assets-map', '/{slug}/assets/map'],
    ['drivers', '/{slug}/drivers'],
    ['rules', '/{slug}/rules'],
    ['automation', '/{slug}/automation'],
    ['analytics', '/{slug}/analytics'],
    ['notifications', '/{slug}/notifications'],
    ['audit', '/{slug}/audit'],
    ['billing', '/{slug}/billing'],
    ['integrations', '/{slug}/integrations'],
    ['settings-roles', '/{slug}/settings/roles'],
    ['tenant-config', '/{slug}/settings/tenant-config'],
    ['settings-profile', '/settings/profile'],
    ['teams', '/settings/teams'],
];

async function loadPlaywright() {
    try {
        return await import('playwright');
    } catch {
        // Fallback a la instalación global (entornos cloud de la rutina).
        return await import('/opt/node22/lib/node_modules/playwright/index.mjs');
    }
}

const { chromium } = await loadPlaywright();

const stamp = new Date().toISOString().slice(0, 10);
const outDir = join(process.cwd(), 'storage', 'app', 'frontend-audit', stamp);
mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch();
let failures = 0;

for (const viewport of VIEWPORTS) {
    const context = await browser.newContext({
        viewport: { width: viewport.width, height: viewport.height },
    });
    const page = await context.newPage();

    // Login una vez por contexto.
    await page.goto(`${BASE}/login`);
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await Promise.all([
        page.waitForURL(/dashboard/),
        page.click('button[type=submit]'),
    ]);
    const slug = new URL(page.url()).pathname.split('/')[1];

    for (const theme of THEMES) {
        await page.evaluate((t) => {
            document.documentElement.classList.toggle('dark', t === 'dark');
            document.cookie = `appearance=${t};path=/`;
        }, theme);

        for (const [name, path] of PAGES) {
            const url = `${BASE}${path.replace('{slug}', slug)}`;

            try {
                await page.goto(url, { waitUntil: 'networkidle' });
                await page.waitForTimeout(400);

                const overflow = await page.evaluate(
                    () =>
                        document.documentElement.scrollWidth -
                        document.documentElement.clientWidth,
                );

                if (overflow > 0) {
                    failures += 1;
                    console.error(
                        `✗ ${name} [${viewport.name}/${theme}]: overflow horizontal de ${overflow}px`,
                    );
                }

                await page.screenshot({
                    path: join(outDir, `${name}--${viewport.name}--${theme}.png`),
                    fullPage: viewport.name === 'desktop',
                });
                console.log(`✓ ${name} [${viewport.name}/${theme}]`);
            } catch (error) {
                failures += 1;
                console.error(
                    `✗ ${name} [${viewport.name}/${theme}]: ${error.message.split('\n')[0]}`,
                );
            }
        }
    }

    await context.close();
}

await browser.close();
console.log(`\nScreenshots en ${outDir}`);

if (failures > 0) {
    console.error(`${failures} página(s) con problemas.`);
    process.exit(1);
}
