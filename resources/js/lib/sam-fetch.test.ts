import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    deleteJson,
    getJson,
    postFormData,
    postJson,
    postStream,
    readErrorMessage,
    readErrorPayload,
} from '@/lib/sam-fetch';

const fetchMock = vi.fn<typeof fetch>();

function lastRequest(): { url: string; init: RequestInit } {
    const [url, init] = fetchMock.mock.lastCall ?? [];

    return { url: String(url), init: init ?? {} };
}

function headersOf(init: RequestInit): Record<string, string> {
    return init.headers as Record<string, string>;
}

function jsonResponse(body: unknown, status = 422): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

beforeEach(() => {
    fetchMock.mockResolvedValue(new Response('{}'));
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    fetchMock.mockReset();
});

describe('mutaciones JSON', () => {
    it('devuelve el token CSRF de la cookie en X-XSRF-TOKEN', async () => {
        document.cookie = 'XSRF-TOKEN=abc%3D%3D';

        await postJson('/x', { a: 1 });

        const { init } = lastRequest();

        expect(init.method).toBe('POST');
        expect(init.credentials).toBe('same-origin');
        expect(headersOf(init)['X-XSRF-TOKEN']).toBe('abc==');
        expect(headersOf(init)['Content-Type']).toBe('application/json');
        expect(init.body).toBe('{"a":1}');
    });

    it('no inventa la cabecera sin cookie', async () => {
        await postJson('/x');

        expect(headersOf(lastRequest().init)).not.toHaveProperty(
            'X-XSRF-TOKEN',
        );
    });

    it('no confunde otra cookie con el token', async () => {
        document.cookie = 'NOT-XSRF-TOKEN=nope';

        await postJson('/x');

        expect(headersOf(lastRequest().init)).not.toHaveProperty(
            'X-XSRF-TOKEN',
        );
    });

    it('manda un cuerpo vacío válido cuando no hay body', async () => {
        await deleteJson('/x/1');

        expect(lastRequest().init.method).toBe('DELETE');
        expect(lastRequest().init.body).toBe('{}');
    });

    it('pasa la señal de cancelación', async () => {
        const controller = new AbortController();

        await postJson('/x', {}, controller.signal);

        expect(lastRequest().init.signal).toBe(controller.signal);
    });
});

describe('getJson', () => {
    it('pide JSON con la sesión y sin cuerpo', async () => {
        await getJson('/buscar?q=t');

        const { url, init } = lastRequest();

        expect(url).toBe('/buscar?q=t');
        expect(init.method).toBeUndefined();
        expect(headersOf(init).Accept).toBe('application/json');
    });
});

describe('postFormData', () => {
    it('deja que el navegador ponga el boundary multipart', async () => {
        document.cookie = 'XSRF-TOKEN=tok';
        const body = new FormData();

        await postFormData('/subir', body);

        const { init } = lastRequest();

        expect(init.body).toBe(body);
        expect(headersOf(init)).not.toHaveProperty('Content-Type');
        expect(headersOf(init)['X-XSRF-TOKEN']).toBe('tok');
    });
});

describe('postStream', () => {
    it('acepta server-sent events', async () => {
        await postStream('/copiloto', { message: 'hola' });

        expect(headersOf(lastRequest().init).Accept).toContain(
            'text/event-stream',
        );
    });
});

describe('readErrorPayload', () => {
    it('toma el primer mensaje de cada campo', async () => {
        const payload = await readErrorPayload(
            jsonResponse({
                message: 'Datos inválidos.',
                errors: {
                    name: ['El nombre es obligatorio.', 'Otro'],
                    phone: ['Teléfono inválido.'],
                },
            }),
        );

        expect(payload).toEqual({
            message: 'Datos inválidos.',
            fieldErrors: {
                name: 'El nombre es obligatorio.',
                phone: 'Teléfono inválido.',
            },
        });
    });

    it('ignora errores con forma inesperada', async () => {
        const payload = await readErrorPayload(
            jsonResponse({ errors: { a: 'texto suelto', b: [42] } }),
        );

        expect(payload).toEqual({ message: null, fieldErrors: {} });
    });

    it('tolera una respuesta que no es JSON', async () => {
        const payload = await readErrorPayload(
            new Response('<html>500</html>', { status: 500 }),
        );

        expect(payload).toEqual({ message: null, fieldErrors: {} });
    });
});

describe('readErrorMessage', () => {
    it('prefiere el error del primer campo', async () => {
        expect(
            await readErrorMessage(
                jsonResponse({
                    message: 'General',
                    errors: { email: ['Correo inválido.'] },
                }),
            ),
        ).toBe('Correo inválido.');
    });

    it('cae al mensaje general', async () => {
        expect(
            await readErrorMessage(jsonResponse({ message: 'General' }, 500)),
        ).toBe('General');
    });
});
