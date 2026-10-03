/**
 * Una sola forma de mutar datos desde el cliente:
 *
 * - Formularios de página (campos ligados a una ruta Inertia): `<Form>` o
 *   `useForm` de Inertia con la ruta de Wayfinder (`store.form(...)`).
 * - Acciones JSON contra rutas web propias (guardar una sección, alternar,
 *   borrar desde un diálogo): `submit(postJson(ruta.url(...), body), '¡Listo!')`.
 *   Avisa con toast, traduce 403/422 y recarga las props.
 * - Lecturas JSON y streaming (paleta, Copiloto): `getJson` / `postStream` de
 *   `@/lib/sam-fetch`, que ponen el CSRF y las cabeceras de Laravel.
 * - `fetch` crudo sólo para URLs externas.
 *
 * Las URLs salen siempre de Wayfinder (`@/routes/...` o `@/actions/...`).
 */
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { readErrorPayload } from '@/lib/sam-fetch';

export interface SubmitResult {
    ok: boolean;
    /** Primer mensaje por campo del `errors` de Laravel (D-04). */
    fieldErrors: Record<string, string>;
}

export interface SubmitOptions {
    /** Toast ante un 403. */
    forbiddenMessage?: string;
    /** Toast ante un error sin mensaje del servidor. */
    errorMessage?: string;
    /** Props a recargar tras el éxito (por omisión, todas). */
    only?: string[];
}

/**
 * Espera una mutación JSON (`postJson`/`putJson`/`deleteJson`…), avisa con
 * toast y, si salió bien, recarga las props de la página.
 */
export async function submit(
    request: Promise<Response>,
    successMessage: string,
    {
        forbiddenMessage = 'No tienes permisos para esta acción.',
        errorMessage = 'No se pudo completar la acción.',
        only,
    }: SubmitOptions = {},
): Promise<SubmitResult> {
    try {
        const response = await request;

        if (response.ok) {
            toast.success(successMessage);
            router.reload(only ? { only } : {});

            return { ok: true, fieldErrors: {} };
        }

        if (response.status === 403) {
            toast.error(forbiddenMessage);

            return { ok: false, fieldErrors: {} };
        }

        const { message, fieldErrors } = await readErrorPayload(response);

        toast.error(Object.values(fieldErrors)[0] ?? message ?? errorMessage);

        return { ok: false, fieldErrors };
    } catch {
        toast.error('Error de red. Vuelve a intentarlo.');
    }

    return { ok: false, fieldErrors: {} };
}
