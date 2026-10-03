import {
    ClearFiltersButton,
    FilterDropdown,
    SearchInput,
} from '@/components/sam/list';
import { SegmentedFilter } from '@/components/sam/segmented-filter';
import { ASSET_STATUS } from '@/lib/labels';
import { toneDotFor } from '@/lib/tone';
import type {
    AssetFilterOptions,
    AssetFilters,
    AssetsSummary,
} from '@/types/assets';

export interface AssetsFilterBarProps {
    filters: AssetFilters;
    options: AssetFilterOptions;
    summary: AssetsSummary | null;
    onApply: (next: AssetFilters) => void;
}

export function AssetsFilterBar({
    filters,
    options,
    summary,
    onApply,
}: AssetsFilterBarProps) {
    const hasActive =
        filters.q !== null ||
        filters.status !== null ||
        filters.type !== null ||
        filters.monitoring !== null;

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
                    allLabel="Todas"
                    allCount={summary.total}
                    options={options.statuses
                        .filter(
                            (o) =>
                                summary.statuses[
                                    o.value as keyof AssetsSummary['statuses']
                                ] > 0 || o.value === filters.status,
                        )
                        .map((o) => ({
                            value: o.value,
                            label: o.label,
                            count: summary.statuses[
                                o.value as keyof AssetsSummary['statuses']
                            ],
                            dot: toneDotFor(ASSET_STATUS, o.value),
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

            {options.types.length > 1 && (
                <FilterDropdown
                    label="Tipo"
                    value={filters.type}
                    options={options.types}
                    onChange={(type) => onApply({ ...filters, type })}
                />
            )}

            <FilterDropdown
                label="Vigilancia"
                value={filters.monitoring}
                options={options.monitoring}
                onChange={(monitoring) => onApply({ ...filters, monitoring })}
            />

            {hasActive && (
                <ClearFiltersButton
                    onClick={() =>
                        onApply({
                            q: null,
                            status: null,
                            type: null,
                            monitoring: null,
                        })
                    }
                />
            )}
        </div>
    );
}
