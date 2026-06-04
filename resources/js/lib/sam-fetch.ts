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
 * POST a JSON body to a session-authenticated route and return the raw
 * Response so callers can branch on the status code.
 */
export function postJson(
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
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-XSRF-TOKEN': token } : {}),
        },
        body: JSON.stringify(body ?? {}),
    });
}

/**
 * Best-effort extraction of a human-readable error message from a Laravel
 * JSON error response (validation `errors` map or top-level `message`).
 */
export async function readErrorMessage(
    response: Response,
): Promise<string | null> {
    try {
        const data = (await response.json()) as {
            message?: string;
            errors?: Record<string, string[]>;
        };

        if (data.errors) {
            const first = Object.values(data.errors)[0];

            if (Array.isArray(first) && first.length > 0) {
                return first[0];
            }
        }

        return data.message ?? null;
    } catch {
        return null;
    }
}
