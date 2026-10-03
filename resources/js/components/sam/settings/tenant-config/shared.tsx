import { usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import type { SubmitOptions } from '@/lib/submit';

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

/** Mensajes de `submit` para los guardados de configuración. */
export const CONFIG_SUBMIT: SubmitOptions = {
    forbiddenMessage: 'No tienes permisos para editar la configuración.',
    errorMessage: 'No se pudo guardar la configuración.',
};

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
