/**
 * Lightweight fetch helpers for the SAM inbox actions.
 *
 * The inbox talks to session-authenticated web routes (not the stateless
 * `api` group), so every mutating request must carry the Laravel CSRF token.
 * Inertia sets the readable `XSRF-TOKEN` cookie; we echo it back in the
 * `X-XSRF-TOKEN` header exactly like axios/Inertia would.
 */

function readCookie(name: string): string | null {
    const match = document.cookie.match(
        new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'),
    );

    return match ? decodeURIComponent(match[1]) : null;
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
            if (Array.isArray(messages) && messages.length > 0) {
                fieldErrors[field] = messages[0];
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
