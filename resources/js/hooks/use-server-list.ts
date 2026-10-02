import { router } from '@inertiajs/react';
import { useState } from 'react';

export type ServerFilterValue = string | number | boolean | null;

/** Forma de un objeto de filtros: cada clave es un valor de query string. */
export type ServerFilters<F> = { [K in keyof F]: ServerFilterValue };

/** `null` y `false` cuentan como "sin filtro". */
export function isFilterActive(value: ServerFilterValue): boolean {
    return value !== null && value !== false;
}

export function hasActiveFilters(filters: object): boolean {
    return (Object.values(filters) as ServerFilterValue[]).some(isFilterActive);
}

/**
 * Filtros → query string: lo vacío va como `undefined` (Inertia lo quita de
 * la URL) y los booleanos activos viajan como `1`, como los espera el backend.
 */
function toQuery(filters: object): Record<string, string | number | undefined> {
    return Object.fromEntries(
        (Object.entries(filters) as [string, ServerFilterValue][]).map(
            ([key, value]) => [
                key,
                value === null || value === false
                    ? undefined
                    : value === true
                      ? 1
                      : value,
            ],
        ),
    );
}

interface UseServerListOptions<F extends ServerFilters<F>> {
    /** Props que trae un cambio de página (filas + paginación). */
    only: string[];
    /** Props que recarga aplicar filtros. Por defecto `only` + `filters`. */
    applyOnly?: string[];
    /** Props que recarga "Refrescar". Por defecto `only`. */
    refreshOnly?: string[];
    /** Filtros aplicados que devuelve el servidor (fuente de verdad). */
    filters: F;
    /** Estado "sin filtros" al que vuelve `reset()`. */
    emptyFilters: F;
    /** Se llama al terminar "Refrescar" (p. ej. para vaciar cachés). */
    onRefreshFinish?: () => void;
}

/**
 * Andamiaje de toda lista paginada en servidor: filtros con respuesta
 * optimista, recarga parcial al aplicarlos (siempre desde la página 1),
 * paginación y "Refrescar".
 *
 * Los filtros del servidor mandan: lo que el usuario acaba de aplicar se
 * muestra mientras llega la respuesta y se descarta en cuanto el servidor
 * devuelve otro conjunto (respuesta, atrás/adelante del navegador). Sin
 * efectos que copien props a estado.
 */
export function useServerList<F extends ServerFilters<F>>({
    only,
    applyOnly,
    refreshOnly,
    filters: serverFilters,
    emptyFilters,
    onRefreshFinish,
}: UseServerListOptions<F>) {
    const serverKey = JSON.stringify(serverFilters);
    const [draft, setDraft] = useState<{ key: string; filters: F | null }>({
        key: serverKey,
        filters: null,
    });
    const [refreshing, setRefreshing] = useState(false);

    // El servidor cambió sus filtros: lo optimista ya no aplica.
    let pending = draft.filters;

    if (draft.key !== serverKey) {
        pending = null;
        setDraft({ key: serverKey, filters: null });
    }

    const filters = pending ?? serverFilters;

    const apply = (next: F) => {
        setDraft({ key: serverKey, filters: next });
        router.reload({
            only: applyOnly ?? [...only, 'filters'],
            data: {
                ...toQuery(next),
                // Cambiar filtros siempre reinicia en la primera página.
                page: undefined,
            },
        });
    };

    const setFilter = <K extends keyof F>(key: K, value: F[K]) =>
        apply({ ...filters, [key]: value });

    const reset = () => apply(emptyFilters);

    const goToPage = (page: number) => {
        router.reload({ only, data: { page } });
    };

    const refresh = () => {
        setRefreshing(true);
        router.reload({
            only: refreshOnly ?? only,
            onFinish: () => {
                setRefreshing(false);
                onRefreshFinish?.();
            },
        });
    };

    return {
        filters,
        apply,
        setFilter,
        reset,
        goToPage,
        refresh,
        refreshing,
        /** Según lo que aplicó el servidor (para el estado vacío). */
        hasActiveFilters: hasActiveFilters(serverFilters),
    };
}
