import { expect, test as setup } from '@playwright/test';
import {
    ACCOUNTS,
    storageStateFor,
    SUPER_ADMIN_TOTP_SECRET,
    TEAM,
} from './support/accounts';
import { signIn } from './support/auth';
import { totp } from './support/totp';

/**
 * Una sesión por rol, guardada para los specs. El super-admin pasa el reto
 * 2FA una sola vez: Fortify rechaza reusar el mismo código TOTP.
 */
for (const role of ['monitor', 'admin'] as const) {
    setup(`sesión de ${role}`, async ({ page }) => {
        await signIn(page, ACCOUNTS[role]);
        await expect(page).toHaveURL(`/${TEAM}/dashboard`);
        await page.context().storageState({ path: storageStateFor(role) });
    });
}

setup('sesión de super-admin (con 2FA)', async ({ page }) => {
    await signIn(page, ACCOUNTS.superAdmin);
    await expect(page).toHaveURL('/two-factor-challenge');

    await page.getByRole('textbox').click();
    await page.keyboard.type(totp(SUPER_ADMIN_TOTP_SECRET));
    await page.getByRole('button', { name: 'Continuar' }).click();

    await expect(page).not.toHaveURL(/two-factor-challenge/);
    await page.context().storageState({ path: storageStateFor('superAdmin') });
});
