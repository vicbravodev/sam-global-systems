import { storageStateFor, TEAM } from './support/accounts';
import { sendPanic, WEBHOOK_SECRET } from './support/samsara';
import { expect, test } from './support/test';

test.use({ storageState: storageStateFor('admin') });

test('al cambiar la Secret Key, sólo entran los pánicos firmados con la nueva', async ({
    page,
    request,
}) => {
    const newSecret = 'e2e-nueva-secret-key-de-samsara';

    await page.goto(`/${TEAM}/integrations`);

    const webhook = page.getByRole('region', {
        name: 'Avisos instantáneos de Samsara',
    });

    await webhook.getByRole('button', { name: 'Cambiar Secret Key' }).click();
    await webhook.getByLabel('Secret Key').fill(newSecret);
    await webhook.getByRole('button', { name: 'Guardar Secret Key' }).click();

    await expect(page.getByText(/Secret Key guardada/).first()).toBeVisible();

    await sendPanic(request, { secret: WEBHOOK_SECRET, vehicle: 'E2E-VIEJA' });
    await sendPanic(request, { secret: newSecret, vehicle: 'E2E-NUEVA' });

    await page.goto(`/${TEAM}/incidents`);

    await expect(
        page.getByRole('row', { name: /Botón de pánico/ }),
    ).toHaveCount(1);
});
