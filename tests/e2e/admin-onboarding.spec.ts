import { storageStateFor } from './support/accounts';
import { expect, test } from './support/test';

test.use({ storageState: storageStateFor('superAdmin') });

test('el super-admin da de alta un cliente nuevo', async ({ page }) => {
    await page.goto('/admin/tenants');
    await page.getByRole('button', { name: 'Nuevo cliente' }).click();

    const dialog = page.getByRole('dialog', { name: 'Nuevo cliente' });
    const company = dialog.getByRole('group', { name: 'Empresa' });
    const owner = dialog.getByRole('group', { name: 'Responsable' });

    await company.getByLabel('Nombre').fill('Transportes E2E');
    await owner.getByLabel('Correo').fill('direccion@transportes-e2e.test');
    await owner.getByLabel('Nombre').fill('Dirección E2E');
    await dialog.getByRole('button', { name: 'Crear cliente' }).click();

    // Al crearlo abre su ficha, con la puesta en marcha pendiente.
    await expect(
        page.getByRole('heading', { name: 'Transportes E2E', level: 1 }),
    ).toBeVisible();
    await expect(page.getByText(/En alta/).first()).toBeVisible();
    await expect(
        page.getByText(/Esperando a direccion@transportes-e2e\.test/),
    ).toBeVisible();

    await page.goto('/admin/tenants');

    await expect(
        page.getByRole('row', { name: /Transportes E2E/ }),
    ).toContainText('Dirección E2E');
});
