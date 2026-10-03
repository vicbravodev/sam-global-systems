import {
    ClearFiltersButton,
    FilterDropdown,
    SearchInput,
} from '@/components/sam/list';
import { SegmentedFilter } from '@/components/sam/segmented-filter';
import { DRIVER_STATUS } from '@/lib/labels';
import { toneDotFor } from '@/lib/tone';
import type {
    DriverFilterOptions,
    DriverFilters,
    DriversSummary,
} from '@/types/drivers';

export interface DriversFilterBarProps {
    filters: DriverFilters;
    options: DriverFilterOptions;
    summary: DriversSummary | null;
    onApply: (next: DriverFilters) => void;
}

export function DriversFilterBar({
    filters,
    options,
    summary,
    onApply,
}: DriversFilterBarProps) {
    const hasActive = filters.q !== null || filters.status !== null;

    return (
        <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-border bg-background px-5 py-2">
            <SearchInput
                value={filters.q}
                onApply={(q) => onApply({ ...filters, q })}
                placeholder="Buscar por nombre o código…"
                className="mr-1"
            />

            {summary ? (
                <SegmentedFilter
                    aria-label="Filtrar por estado"
                    value={filters.status}
                    onChange={(status) => onApply({ ...filters, status })}
                    allCount={summary.total}
                    options={options.statuses.map((o) => ({
                        value: o.value,
                        label: o.label,
                        count: summary.statuses[
                            o.value as keyof DriversSummary['statuses']
                        ],
                        dot: toneDotFor(DRIVER_STATUS, o.value),
                    }))}
                />
            ) : (
                <FilterDropdown
                    label="Estado"
                    value={filters.status}
                    options={options.statuses}
                    onChange={(status) => onApply({ ...filters, status })}
                />
            )}

            {hasActive && (
                <ClearFiltersButton
                    onClick={() => onApply({ q: null, status: null })}
                />
            )}
        </div>
    );
}
