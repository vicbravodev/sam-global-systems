import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { submit } from '@/lib/submit';

vi.mock('@inertiajs/react', () => ({ router: { reload: vi.fn() } }));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

function respond(status: number, body: unknown = {}): Promise<Response> {
    return Promise.resolve(new Response(JSON.stringify(body), { status }));
}

beforeEach(() => {
    vi.mocked(router.reload).mockClear();
    vi.mocked(toast.success).mockClear();
    vi.mocked(toast.error).mockClear();
});

describe('submit', () => {
    it('avisa el éxito y recarga todas las props', async () => {
        const result = await submit(respond(200), 'Guardado');

        expect(result).toEqual({ ok: true, fieldErrors: {} });
        expect(toast.success).toHaveBeenCalledWith('Guardado');
        expect(router.reload).toHaveBeenCalledWith({});
    });

    it('recarga sólo las props pedidas', async () => {
        await submit(respond(201), 'Listo', { only: ['rules'] });

        expect(router.reload).toHaveBeenCalledWith({ only: ['rules'] });
    });

    it('traduce un 403 sin recargar', async () => {
        const result = await submit(respond(403), 'Guardado');

        expect(result.ok).toBe(false);
        expect(toast.error).toHaveBeenCalledWith(
            'No tienes permisos para esta acción.',
        );
        expect(router.reload).not.toHaveBeenCalled();
    });

    it('devuelve los errores por campo de un 422 y muestra el primero', async () => {
        const result = await submit(
            respond(422, {
                message: 'Datos inválidos.',
                errors: { name: ['El nombre es obligatorio.'] },
            }),
            'Guardado',
        );

        expect(result).toEqual({
            ok: false,
            fieldErrors: { name: 'El nombre es obligatorio.' },
        });
        expect(toast.error).toHaveBeenCalledWith('El nombre es obligatorio.');
        expect(router.reload).not.toHaveBeenCalled();
    });

    it('usa el mensaje del servidor o el genérico', async () => {
        await submit(respond(500, { message: 'Se cayó Samsara.' }), 'Ok');
        expect(toast.error).toHaveBeenLastCalledWith('Se cayó Samsara.');

        await submit(respond(500), 'Ok', { errorMessage: 'No se guardó.' });
        expect(toast.error).toHaveBeenLastCalledWith('No se guardó.');
    });

    it('avisa un error de red', async () => {
        const result = await submit(
            Promise.reject(new TypeError('Failed to fetch')),
            'Ok',
        );

        expect(result).toEqual({ ok: false, fieldErrors: {} });
        expect(toast.error).toHaveBeenCalledWith(
            'Error de red. Vuelve a intentarlo.',
        );
    });
});
