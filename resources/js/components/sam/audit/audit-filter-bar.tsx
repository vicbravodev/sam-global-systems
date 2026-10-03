import { ClearFiltersButton, SearchInput } from '@/components/sam/list';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { AuditFilterOptions, AuditFilters } from './types';

// Sentinel para representar "sin filtro" en los <Select> del DS: Radix no
// permite SelectItem con value="", así que el filtro vacío (todas/todos) se
// traduce a/desde este string en el handler.
const ALL_OPTION = '__all__';

export const EMPTY_AUDIT_FILTERS: AuditFilters = {
    q: null,
    category: null,
    actor_type: null,
    from: null,
    to: null,
    system: false,
};

export interface AuditFilterBarProps {
    filters: AuditFilters;
    filterOptions: AuditFilterOptions;
    setFilter: <K extends keyof AuditFilters>(
        key: K,
        value: AuditFilters[K],
    ) => void;
    apply: (next: AuditFilters) => void;
}

export function AuditFilterBar({
    filters,
    filterOptions,
    setFilter,
    apply,
}: AuditFilterBarProps) {
    // "Mostrar actividad del sistema" es una preferencia de vista, no un
    // filtro: ni cuenta para "Limpiar" ni se borra con él.
    const hasActive = (
        ['q', 'category', 'actor_type', 'from', 'to'] as const
    ).some((key) => filters[key] !== null);

    return (
        <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-border bg-background px-5 py-2">
            <SearchInput
                value={filters.q}
                onApply={(q) => setFilter('q', q)}
                placeholder="Buscar acción, entidad…"
            />
            <Select
                value={filters.category ?? ALL_OPTION}
                onValueChange={(value) =>
                    setFilter('category', value === ALL_OPTION ? null : value)
                }
            >
                <SelectTrigger aria-label="Categoría" className="h-9">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL_OPTION}>Categoría: todas</SelectItem>
                    {filterOptions.categories.map((category) => (
                        <SelectItem key={category.value} value={category.value}>
                            {category.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <Select
                value={filters.actor_type ?? ALL_OPTION}
                onValueChange={(value) =>
                    setFilter('actor_type', value === ALL_OPTION ? null : value)
                }
            >
                <SelectTrigger aria-label="Actor" className="h-9">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL_OPTION}>Actor: todos</SelectItem>
                    {filterOptions.actorTypes.map((actor) => (
                        <SelectItem key={actor.value} value={actor.value}>
                            {actor.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <input
                type="date"
                aria-label="Desde"
                value={filters.from ?? ''}
                onChange={(event) =>
                    setFilter(
                        'from',
                        event.target.value === '' ? null : event.target.value,
                    )
                }
                className="rounded-md border border-border bg-surface-1 px-2 py-1 text-xs text-fg-2"
            />
            <input
                type="date"
                aria-label="Hasta"
                value={filters.to ?? ''}
                onChange={(event) =>
                    setFilter(
                        'to',
                        event.target.value === '' ? null : event.target.value,
                    )
                }
                className="rounded-md border border-border bg-surface-1 px-2 py-1 text-xs text-fg-2"
            />
            <label className="flex cursor-pointer items-center gap-1.5 text-xs text-fg-2">
                <input
                    type="checkbox"
                    checked={filters.system}
                    onChange={(event) =>
                        setFilter('system', event.target.checked)
                    }
                    className="accent-primary"
                />
                Mostrar actividad automática del sistema
            </label>
            {hasActive && (
                <ClearFiltersButton
                    onClick={() =>
                        apply({
                            ...EMPTY_AUDIT_FILTERS,
                            system: filters.system,
                        })
                    }
                />
            )}
        </div>
    );
}
