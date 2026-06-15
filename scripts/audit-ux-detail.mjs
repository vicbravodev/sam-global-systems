#!/usr/bin/env node
/** Captura páginas de detalle haciendo click en la primera fila/tarjeta. */
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import process from 'node:process';

const BASE = 'http://localhost';
const CREDS = { email: 'admin@serviexpress.test', password: 'password' };
const VIEWPORTS = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'mobile', width: 390, height: 844 },
];
const LISTS = ['incidents', 'events', 'drivers', 'assets'];

async function loadPlaywright() {
    for (const c of [
        'playwright',
        '/Users/victorjesusbravodelapena/.npm/_npx/e41f203b7505f1fb/node_modules/playwright/index.mjs',
    ]) {
        try {
            return await import(c);
        } catch {
            /* next */
        }
    }
    throw new Error('no playwright');
}
const { chromium } = await loadPlaywright();
const outDir = join(process.cwd(), 'storage', 'app', 'ux-audit', new Date().toISOString().slice(0, 10));
mkdirSync(outDir, { recursive: true });
const browser = await chromium.launch();

for (const viewport of VIEWPORTS) {
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    await page.goto(`${BASE}/login`);
    await page.fill('input[type=email]', CREDS.email);
    await page.fill('input[type=password]', CREDS.password);
    await Promise.all([page.waitForNavigation().catch(() => {}), page.click('button[type=submit]')]);
    await page.waitForTimeout(1000);
    const slug = new URL(page.url()).pathname.split('/')[1];
    await page.evaluate(() => document.documentElement.classList.add('dark'));

    for (const seg of LISTS) {
        try {
            await page.goto(`${BASE}/${slug}/${seg}`, { waitUntil: 'networkidle', timeout: 15000 });
            await page.waitForTimeout(800);
            const before = page.url();
            // Intenta varios selectores de fila clickable.
            const clicked = await page.evaluate(() => {
                const sel = [
                    'tbody tr',
                    '[role="row"]',
                    '[data-row]',
                    'table tr:not(:first-child)',
                ];
                for (const s of sel) {
                    const el = document.querySelector(s);
                    if (el) {
                        el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
                        return true;
                    }
                }
                return false;
            });
            await page.waitForTimeout(1500);
            const after = page.url();
            const moved = after !== before;
            await page.screenshot({
                path: join(outDir, `detail-${seg}--${viewport.name}--dark.png`),
                fullPage: viewport.name === 'desktop',
            });
            console.log(`${seg} [${viewport.name}]: clicked=${clicked} navTo=${moved ? after : '(panel/same url)'}`);
        } catch (e) {
            console.error(`${seg} [${viewport.name}]: ${e.message.split('\n')[0]}`);
        }
    }
    await context.close();
}
await browser.close();
console.log('done');
