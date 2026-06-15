#!/usr/bin/env node
/**
 * Auditoría de usabilidad (one-off): conduce Chrome real con Playwright,
 * navega público + tenant + superadmin, en desktop/móvil y oscuro/claro,
 * drilea a páginas de detalle reales y guarda screenshots en
 * `storage/app/ux-audit/{fecha}/`.
 *
 * Uso:
 *   node scripts/audit-ux.mjs --base=http://localhost
 */
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import process from 'node:process';

const args = Object.fromEntries(
    process.argv
        .slice(2)
        .filter((a) => a.startsWith('--'))
        .map((a) => a.replace(/^--/, '').split('=')),
);

const BASE = args.base ?? 'http://localhost';

const VIEWPORTS = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'mobile', width: 390, height: 844 },
];
const THEMES = ['dark', 'light'];

const TENANT = { email: 'admin@serviexpress.test', password: 'password' };
const SUPERADMIN = { email: 'superadmin@sam.test', password: 'password' };

// Páginas públicas (sin auth).
const PUBLIC_PAGES = [
    ['public-welcome', '/'],
    ['public-login', '/login'],
    ['public-register', '/register'],
    ['public-forgot', '/forgot-password'],
];

// Páginas del tenant (slug se sustituye tras login).
const TENANT_PAGES = [
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
    ['settings-notifications', '/settings/notifications'],
    ['settings-security', '/settings/security'],
    ['settings-appearance', '/settings/appearance'],
    ['teams', '/settings/teams'],
];

// Listas desde las que dril­eamos a una página de detalle real.
const DRILL = [
    ['detail-incident', 'incidents'],
    ['detail-event', 'events'],
    ['detail-asset', 'assets'],
    ['detail-driver', 'drivers'],
];

const ADMIN_PAGES = [
    ['admin-tenants', '/admin/tenants'],
    ['admin-plans', '/admin/plans'],
    ['admin-operators', '/admin/operators'],
    ['admin-channels', '/admin/channels'],
    ['admin-audit', '/admin/audit'],
];

async function loadPlaywright() {
    const candidates = [
        'playwright',
        '/Users/victorjesusbravodelapena/.npm/_npx/e41f203b7505f1fb/node_modules/playwright/index.mjs',
        '/opt/node22/lib/node_modules/playwright/index.mjs',
    ];
    for (const c of candidates) {
        try {
            return await import(c);
        } catch {
            /* siguiente */
        }
    }
    throw new Error('Playwright no encontrado');
}

const { chromium } = await loadPlaywright();

const stamp = new Date().toISOString().slice(0, 10);
const outDir = join(process.cwd(), 'storage', 'app', 'ux-audit', stamp);
mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch();
const log = [];

async function setTheme(page, theme) {
    await page.evaluate((t) => {
        document.documentElement.classList.toggle('dark', t === 'dark');
        document.cookie = `appearance=${t};path=/`;
    }, theme);
}

async function shoot(page, name, viewport, theme) {
    try {
        await page.waitForTimeout(500);
        const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        );
        await page.screenshot({
            path: join(outDir, `${name}--${viewport.name}--${theme}.png`),
            fullPage: viewport.name === 'desktop',
        });
        const flag = overflow > 0 ? ` ⚠ overflow ${overflow}px` : '';
        console.log(`✓ ${name} [${viewport.name}/${theme}]${flag}`);
        if (overflow > 0) log.push(`OVERFLOW ${name} ${viewport.name}/${theme}: ${overflow}px`);
    } catch (e) {
        console.error(`✗ ${name} [${viewport.name}/${theme}]: ${e.message.split('\n')[0]}`);
        log.push(`FAIL ${name} ${viewport.name}/${theme}: ${e.message.split('\n')[0]}`);
    }
}

async function go(page, url) {
    try {
        await page.goto(url, { waitUntil: 'networkidle', timeout: 15000 });
    } catch {
        await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => {});
    }
}

async function login(page, creds) {
    await go(page, `${BASE}/login`);
    await page.fill('input[type=email]', creds.email);
    await page.fill('input[type=password]', creds.password);
    await Promise.all([
        page.waitForNavigation({ timeout: 15000 }).catch(() => {}),
        page.click('button[type=submit]'),
    ]);
    await page.waitForTimeout(1000);
}

// --- Público ---
for (const viewport of VIEWPORTS) {
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    for (const theme of THEMES) {
        for (const [name, path] of PUBLIC_PAGES) {
            await go(page, `${BASE}${path}`);
            await setTheme(page, theme);
            await shoot(page, name, viewport, theme);
        }
    }
    await context.close();
}

// --- Tenant ---
for (const viewport of VIEWPORTS) {
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    await login(page, TENANT);
    const slug = new URL(page.url()).pathname.split('/')[1] || 'app';
    for (const theme of THEMES) {
        await setTheme(page, theme);
        for (const [name, path] of TENANT_PAGES) {
            await go(page, `${BASE}${path.replace('{slug}', slug)}`);
            await setTheme(page, theme);
            await shoot(page, name, viewport, theme);
        }
        for (const [name, segment] of DRILL) {
            await go(page, `${BASE}/${slug}/${segment}`);
            const href = await page.evaluate((seg) => {
                const re = new RegExp(`/${seg}/[A-Za-z0-9-]+$`);
                const a = [...document.querySelectorAll('a')].find((el) =>
                    re.test(new URL(el.href, location.origin).pathname),
                );
                return a ? a.href : null;
            }, segment);
            if (href) {
                await go(page, href);
                await setTheme(page, theme);
                await shoot(page, name, viewport, theme);
            } else {
                console.log(`· ${name}: sin fila para drilear`);
                log.push(`NODRILL ${name}: sin fila en ${segment}`);
            }
        }
    }
    await context.close();
}

// --- Superadmin ---
for (const viewport of VIEWPORTS) {
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    await login(page, SUPERADMIN);
    for (const theme of THEMES) {
        await setTheme(page, theme);
        for (const [name, path] of ADMIN_PAGES) {
            await go(page, `${BASE}${path}`);
            await setTheme(page, theme);
            await shoot(page, name, viewport, theme);
        }
        // Drill al primer tenant.
        await go(page, `${BASE}/admin/tenants`);
        const href = await page.evaluate(() => {
            const a = [...document.querySelectorAll('a')].find((el) =>
                /\/admin\/tenants\/[A-Za-z0-9-]+$/.test(new URL(el.href, location.origin).pathname),
            );
            return a ? a.href : null;
        });
        if (href) {
            await go(page, href);
            await setTheme(page, theme);
            await shoot(page, 'admin-tenant-detail', viewport, theme);
        }
    }
    await context.close();
}

await browser.close();
console.log(`\nScreenshots en ${outDir}`);
console.log(`\n--- Notas ---\n${log.join('\n') || 'sin overflow ni fallos'}`);
