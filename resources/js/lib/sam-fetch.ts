/**
 * Transporte de bajo nivel hacia las rutas web propias (sesión + CSRF).
 *
 * Las rutas viven en el grupo `web` (no en el `api` sin estado), así que toda
 * petición que muta lleva el token CSRF: Inertia deja la cookie legible
 * `XSRF-TOKEN` y aquí se devuelve en la cabecera `X-XSRF-TOKEN`, igual que
 * axios/Inertia.
 *
 * Cuándo usar qué (ver también `@/lib/submit`):
 * - Formularios de página: `<Form>` / `useForm` de Inertia.
 * - Acciones JSON con toast + recarga: `submit(postJson(...), '...')`.
 * - Lecturas JSON, subidas y streaming: `getJson`, `postFormData`,
 *   `postStream` de este archivo (aceptan `AbortSignal`).
 * - `fetch` crudo sólo para URLs externas.
 */

function readCookie(name: string): string | null {
    const match = document.cookie.match(
        new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'),
    );

    const value = match?.[1];

    return value === undefined ? null : decodeURIComponent(value);
}

/**
 * Send a JSON body to a session-authenticated route and return the raw
 * Response so callers can branch on the status code. Attaches the CSRF token
 * read from the `XSRF-TOKEN` cookie, exactly like axios/Inertia would.
 */
function sendJson(
    method: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
    url: string,
    body?: Record<string, unknown>,
    signal?: AbortSignal,
): Promise<Response> {
    const token = readCookie('XSRF-TOKEN');

    return fetch(url, {
        method,
        credentials: 'same-origin',
        signal,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-XSRF-TOKEN': token } : {}),
        },
        body: JSON.stringify(body ?? {}),
    });
}

/**
 * GET a session-authenticated route that answers JSON (palette search,
 * Copilot catalog, inbox detail). Returns the raw Response.
 */
export function getJson(url: string, signal?: AbortSignal): Promise<Response> {
    return fetch(url, {
        credentials: 'same-origin',
        signal,
        headers: { Accept: 'application/json' },
    });
}

/**
 * POST a multipart body (file uploads) with the CSRF token. The browser sets
 * the multipart Content-Type boundary itself.
 */
export function postFormData(
    url: string,
    body: FormData,
    signal?: AbortSignal,
): Promise<Response> {
    const token = readCookie('XSRF-TOKEN');

    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        signal,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-XSRF-TOKEN': token } : {}),
        },
        body,
    });
}

/**
 * POST a JSON body to a session-authenticated route.
 */
export function postJson(
    url: string,
    body?: Record<string, unknown>,
    signal?: AbortSignal,
): Promise<Response> {
    return sendJson('POST', url, body, signal);
}

/**
 * POST a JSON body and keep the response open as a server-sent event
 * stream (Copilot). Same CSRF handling as postJson.
 */
export function postStream(
    url: string,
    body?: Record<string, unknown>,
    signal?: AbortSignal,
): Promise<Response> {
    const token = readCookie('XSRF-TOKEN');

    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        signal,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json, text/event-stream',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-XSRF-TOKEN': token } : {}),
        },
        body: JSON.stringify(body ?? {}),
    });
}

/**
 * PUT a JSON body to a session-authenticated route.
 */
export function putJson(
    url: string,
    body?: Record<string, unknown>,
    signal?: AbortSignal,
): Promise<Response> {
    return sendJson('PUT', url, body, signal);
}

/**
 * PATCH a JSON body to a session-authenticated route.
 */
export function patchJson(
    url: string,
    body?: Record<string, unknown>,
    signal?: AbortSignal,
): Promise<Response> {
    return sendJson('PATCH', url, body, signal);
}

/**
 * DELETE a session-authenticated route, optionally with a JSON body.
 */
export function deleteJson(
    url: string,
    body?: Record<string, unknown>,
    signal?: AbortSignal,
): Promise<Response> {
    return sendJson('DELETE', url, body, signal);
}

/**
 * Parsed Laravel error response: the top-level `message` plus the first
 * message per field from the validation `errors` map, ready to paint inline
 * next to each input (D-04).
 */
export interface ErrorPayload {
    message: string | null;
    fieldErrors: Record<string, string>;
}

/**
 * Best-effort extraction of the full Laravel JSON error payload (validation
 * `errors` map + top-level `message`). Consumes the response body.
 */
export async function readErrorPayload(
    response: Response,
): Promise<ErrorPayload> {
    try {
        const data = (await response.json()) as {
            message?: string;
            errors?: Record<string, string[]>;
        };

        const fieldErrors: Record<string, string> = {};

        for (const [field, messages] of Object.entries(data.errors ?? {})) {
            const first: unknown = Array.isArray(messages)
                ? messages[0]
                : undefined;

            if (typeof first === 'string') {
                fieldErrors[field] = first;
            }
        }

        return { message: data.message ?? null, fieldErrors };
    } catch {
        return { message: null, fieldErrors: {} };
    }
}

/**
 * Best-effort extraction of a human-readable error message from a Laravel
 * JSON error response (validation `errors` map or top-level `message`).
 */
export async function readErrorMessage(
    response: Response,
): Promise<string | null> {
    const { message, fieldErrors } = await readErrorPayload(response);
    const first = Object.values(fieldErrors)[0];

    return first ?? message;
}
