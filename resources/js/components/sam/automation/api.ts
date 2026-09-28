import { router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import type { ConditionFieldDef } from '@/components/sam/condition-builder';
import { readErrorPayload } from '@/lib/sam-fetch';

/** Base de las rutas web de automatización del equipo actual. */
export function useAutomationBase(): string | null {
    const page = usePage();
    const slug =
        (
            page.props as unknown as {
                currentTeam?: { slug?: string | null } | null;
            }
        ).currentTeam?.slug ?? null;

    return slug ? `/${slug}/automation` : null;
}

export function useTeamSlug(): string | null {
    const page = usePage();

    return (
        (
            page.props as unknown as {
                currentTeam?: { slug?: string | null } | null;
            }
        ).currentTeam?.slug ?? null
    );
}

export interface SubmitResult {
    ok: boolean;
    /** Primer mensaje por campo del `errors` de Laravel (D-04). */
    fieldErrors: Record<string, string>;
}

/** Ejecuta una mutación JSON, avisa con toast y recarga la página. */
export async function submit(
    promise: Promise<Response>,
    successMessage: string,
): Promise<SubmitResult> {
    try {
        const response = await promise;

        if (response.ok) {
            toast.success(successMessage);
            router.reload();

            return { ok: true, fieldErrors: {} };
        }

        if (response.status === 403) {
            toast.error('No tienes permisos para esta acción.');

            return { ok: false, fieldErrors: {} };
        }

        const { message, fieldErrors } = await readErrorPayload(response);

        toast.error(
            Object.values(fieldErrors)[0] ??
                message ??
                'No se pudo completar la acción.',
        );

        return { ok: false, fieldErrors };
    } catch {
        toast.error('Error de red. Vuelve a intentarlo.');
    }

    return { ok: false, fieldErrors: {} };
}

/** "Prioridad del incidente: Alta" por cada condición del disparador. */
export function conditionPhrases(
    conditions: Record<string, unknown> | null,
    fields: ConditionFieldDef[],
): string[] {
    if (!conditions) {
        return [];
    }

    return Object.entries(conditions).map(([key, value]) => {
        const field = fields.find((f) => f.key === key);
        const label = field?.label ?? humanize(key);
        const option = field?.options.find(
            (o) => String(o.value) === String(value),
        );

        // Una etiqueta igual al código crudo no es etiqueta: se humaniza.
        const shown =
            option && option.label !== String(option.value)
                ? option.label
                : humanize(String(value));

        return `${label}: ${shown}`;
    });
}

export function humanize(value: string): string {
    const text = value
        .replace(/[_.-]+/g, ' ')
        .trim()
        .toLowerCase();

    return text === '' ? '—' : text.charAt(0).toUpperCase() + text.slice(1);
}

/** Código interno único a partir del nombre (el usuario nunca lo ve). */
export function codeFromName(name: string): string {
    const slug = name
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 40);

    return `${slug || 'automatizacion'}-${Date.now().toString(36)}`;
}
