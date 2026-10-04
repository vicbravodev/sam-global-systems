import { storageStateFor, TEAM } from './support/accounts';
import { sendPanic } from './support/samsara';
import { expect, test } from './support/test';

test.use({ storageState: storageStateFor('monitor') });

test('el monitorista toma y resuelve un pánico', async ({ page, request }) => {
    await sendPanic(request);
    await page.goto(`/${TEAM}/incidents`);
    await page
        .getByRole('row', { name: /Botón de pánico/ })
        .getByText('Botón de pánico')
        .click();

    const management = page
        .getByRole('heading', { name: 'Gestión' })
        .locator('..');

    await management.getByRole('button', { name: 'Tomar' }).click();

    await expect(page.getByText('Incidente tomado.')).toBeVisible();
    await expect(management).toContainText('Monitor ServiExpress');
    await expect(
        management.getByRole('button', { name: 'Soltar' }),
    ).toBeVisible();

    await management
        .getByRole('button', { name: 'Resolver incidente' })
        .click();

    const dialog = page.getByRole('dialog', { name: 'Resolver incidente' });
    const resolve = dialog.getByRole('button', { name: 'Resolver' });

    await expect(resolve).toBeDisabled();

    await dialog
        .getByLabel('Resumen')
        .fill('Se habló con el chofer: botón presionado por error.');
    await resolve.click();

    await expect(dialog).toBeHidden();

    await page.goto(`/${TEAM}/incidents`);

    await expect(page.getByText('Nada en esta pestaña')).toBeVisible();

    await page.getByRole('tab', { name: 'Todos' }).click();

    await expect(
        page.getByRole('row', { name: /Botón de pánico/ }),
    ).toContainText(/Resuelto/);
});
