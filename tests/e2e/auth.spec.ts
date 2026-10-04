import { ACCOUNTS, TEAM } from './support/accounts';
import { signIn } from './support/auth';
import { expect, test } from './support/test';

test.describe('inicio de sesión', () => {
    test('con credenciales válidas entra al panel de su equipo', async ({
        page,
    }) => {
        await signIn(page, ACCOUNTS.monitor);

        await expect(page).toHaveURL(`/${TEAM}/dashboard`);
        await expect(
            page.getByRole('button', { name: /Menú de usuario/ }),
        ).toContainText('Monitor ServiExpress');
    });

    test('con contraseña incorrecta no entra y lo dice', async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Correo electrónico').fill(ACCOUNTS.monitor);
        await page.getByLabel('Contraseña', { exact: true }).fill('nope');
        await page.getByRole('button', { name: 'Iniciar sesión' }).click();

        await expect(
            page.getByText(
                'Estas credenciales no coinciden con nuestros registros.',
            ),
        ).toBeVisible();
        await expect(page).toHaveURL('/login');
    });

    test('sin sesión, la bandeja manda al login', async ({ page }) => {
        await page.goto(`/${TEAM}/incidents`);

        await expect(page).toHaveURL('/login');
    });
});
