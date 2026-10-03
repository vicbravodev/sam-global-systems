import { usePage } from '@inertiajs/react';
import type { ConditionFieldDef } from '@/components/sam/condition-builder';
import { humanizeCode } from '@/lib/labels';

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
        const label = field?.label ?? humanizeCode(key);
        const option = field?.options.find(
            (o) => String(o.value) === String(value),
        );

        // Una etiqueta igual al código crudo no es etiqueta: se humaniza.
        const shown =
            option && option.label !== String(option.value)
                ? option.label
                : humanizeCode(String(value));

        return `${label}: ${shown}`;
    });
}
