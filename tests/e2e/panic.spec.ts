import { storageStateFor, TEAM } from './support/accounts';
import { sendPanic } from './support/samsara';
import { expect, test } from './support/test';

test.use({ storageState: storageStateFor('monitor') });

test.describe('pánico de Samsara → bandeja', () => {
    test('un pánico firmado abre un incidente visible en la bandeja', async ({
        page,
        request,
    }) => {
        const response = await sendPanic(request, { vehicle: 'E2E-01' });

        expect(response.status()).toBe(202);

        await page.goto(`/${TEAM}/incidents`);

        await expect(page.getByRole('tab', { name: /Abiertos/ })).toContainText(
            '1',
        );

        const row = page.getByRole('row', { name: /Botón de pánico/ });

        await expect(row).toHaveCount(1);
        await expect(row).toContainText('INC-00001');

        // La fila abre el detalle al lado, sin salir de la bandeja.
        await row.getByText('Botón de pánico').click();

        await expect(
            page.getByRole('heading', { name: 'Botón de pánico', level: 2 }),
        ).toBeVisible();

        // La ficha completa es la que abren los enlaces de los avisos.
        await page.getByRole('link', { name: 'Abrir detalle' }).click();

        await expect(page).toHaveURL(new RegExp(`/${TEAM}/incidents/\\d+$`));
        await expect(
            page.getByRole('heading', { name: 'Botón de pánico', level: 2 }),
        ).toBeVisible();
        await expect(page.getByText('Tipo de evento')).toBeVisible();
    });

    test('un pánico con firma inválida no crea nada', async ({
        page,
        request,
    }) => {
        await sendPanic(request, { secret: 'llave-equivocada' });

        await page.goto(`/${TEAM}/incidents`);

        await expect(page.getByText('Sin incidentes')).toBeVisible();
    });

    test('pánicos distintos no se fusionan en uno', async ({
        page,
        request,
    }) => {
        await sendPanic(request, { vehicle: 'E2E-01' });
        await sendPanic(request, { vehicle: 'E2E-02' });

        await page.goto(`/${TEAM}/incidents`);

        await expect(
            page.getByRole('row', { name: /Botón de pánico/ }),
        ).toHaveCount(2);
    });
});
