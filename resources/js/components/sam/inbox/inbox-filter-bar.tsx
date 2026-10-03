import {
    ClearFiltersButton,
    FilterDropdown,
    SearchInput,
} from '@/components/sam/list';
import { hasActiveFilters } from '@/hooks/use-server-list';
import type { InboxFilterOptions, InboxFilters } from '@/types/sam';

export interface InboxFilterBarProps {
    filters: InboxFilters;
    options: InboxFilterOptions;
    onApply: (next: InboxFilters) => void;
    onReset: () => void;
}

export function InboxFilterBar({
    filters,
    options,
    onApply,
    onReset,
}: InboxFilterBarProps) {
    const providerOptions = options.providers.map((p) => ({
        value: p,
        label: p,
    }));

    return (
        <div className="scrollbar-none flex shrink-0 items-center gap-2 overflow-x-auto border-b border-border bg-background px-5 py-2">
            <SearchInput
                value={filters.q}
                onApply={(q) => onApply({ ...filters, q })}
                placeholder="Buscar incidente…"
                className="mr-1 shrink-0"
            />

            <FilterDropdown
                label="Severidad"
                value={filters.severity}
                options={options.severities}
                onChange={(v) => onApply({ ...filters, severity: v })}
                className="shrink-0"
            />
            <FilterDropdown
                label="Estado"
                value={filters.status}
                options={options.statuses}
                onChange={(v) => onApply({ ...filters, status: v })}
                className="shrink-0"
            />
            <FilterDropdown
                label="Proveedor"
                value={filters.provider}
                options={providerOptions}
                onChange={(v) => onApply({ ...filters, provider: v })}
                className="shrink-0"
            />
            <FilterDropdown
                label="Turno"
                value={filters.shift}
                options={options.shifts}
                onChange={(v) => onApply({ ...filters, shift: v })}
                className="shrink-0"
            />

            {hasActiveFilters(filters) && (
                <ClearFiltersButton onClick={onReset} className="shrink-0" />
            )}
        </div>
    );
}
