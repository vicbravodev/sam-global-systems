import { router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import { readErrorPayload } from '@/lib/sam-fetch';

/** URL base de los endpoints de configuración del equipo activo. */
export function useTeamBase(): string | null {
    const page = usePage();
    const slug =
        (
            page.props as unknown as {
                currentTeam?: { slug?: string | null } | null;
            }
        ).currentTeam?.slug ?? null;

    return slug ? `/${slug}/settings/tenant-config` : null;
}

export interface SubmitResult {
    ok: boolean;
    /** Primer mensaje por campo del `errors` de Laravel (D-04). */
    fieldErrors: Record<string, string>;
}

/** Envía un guardado JSON, muestra el resultado y recarga las props. */
export async function submit(
    promise: Promise<Response>,
    successMessage: string,
): Promise<SubmitResult> {
    try {
        const response = await promise;

        if (response.ok || response.status === 201) {
            toast.success(successMessage);
            router.reload();

            return { ok: true, fieldErrors: {} };
        }

        if (response.status === 403) {
            toast.error('No tienes permisos para editar la configuración.');

            return { ok: false, fieldErrors: {} };
        }

        const { message, fieldErrors } = await readErrorPayload(response);

        toast.error(
            Object.values(fieldErrors)[0] ??
                message ??
                'No se pudo guardar la configuración.',
        );

        return { ok: false, fieldErrors };
    } catch {
        toast.error('Error de red. Vuelve a intentarlo.');
    }

    return { ok: false, fieldErrors: {} };
}

/** Filtra una lista de canales deseados a los que SAM entrega. */
export function providedOr(
    provided: { value: string }[],
    wanted: string[],
): string[] {
    const available = new Set(provided.map((channel) => channel.value));
    const kept = wanted.filter((channel) => available.has(channel));

    return kept.length > 0 ? kept : [...available].slice(0, 1);
}

export function parseJson(
    raw: string,
    label: string,
): Record<string, unknown> | unknown[] | null {
    try {
        return JSON.parse(raw) as Record<string, unknown> | unknown[];
    } catch {
        toast.error(`El formato de ${label} no es válido.`);

        return null;
    }
}

/**
 * Editor de texto para estructuras que el formulario visual no representa:
 * se conserva para no perder datos, pero con aviso de que es avanzado.
 */
export function JsonField({
    id,
    value,
    onChange,
    disabled,
    rows = 6,
}: {
    id: string;
    value: string;
    onChange: (next: string) => void;
    disabled: boolean;
    rows?: number;
}) {
    return (
        <textarea
            id={id}
            value={value}
            disabled={disabled}
            onChange={(e) => onChange(e.target.value)}
            rows={rows}
            spellCheck={false}
            className="w-full rounded-md border border-border bg-surface-2 p-2 font-mono text-2xs leading-relaxed text-fg-2"
        />
    );
}
