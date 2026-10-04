import type { Page } from '@playwright/test';
import { PASSWORD } from './accounts';

export async function signIn(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Correo electrónico').fill(email);
    await page.getByLabel('Contraseña', { exact: true }).fill(PASSWORD);
    await page.getByRole('button', { name: 'Iniciar sesión' }).click();
}
