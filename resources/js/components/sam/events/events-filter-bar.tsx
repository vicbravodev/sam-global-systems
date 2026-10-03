import { SEVERITY_DOT, toSeverity } from '@/components/sam/event-severity';
import {
    ClearFiltersButton,
    FilterDropdown,
    SearchInput,
} from '@/components/sam/list';
import { SegmentedFilter } from '@/components/sam/segmented-filter';
import { priorityLabel } from '@/lib/labels';
import { cn } from '@/lib/utils';
import type { EventFilterOptions, EventFilters } from '@/types/events';
import {
    activeQuickRange,
    EMPTY_EVENT_FILTERS,
    isoDaysAgo,
    QUICK_RANGES,
} from './lib';

export function EventsFilterBar({
    filters,
    options,
    onApply,
}: {
    filters: EventFilters;
    options: EventFilterOptions;
    onApply: (next: EventFilters) => void;
}) {
    const hasActive = Object.values(filters).some((value) => value !== null);
    const quick = activeQuickRange(filters);

    return (
        <div className="flex shrink-0 flex-col gap-2 border-b border-border bg-background px-5 py-2">
            <div className="flex flex-wrap items-center gap-2">
                <SearchInput
                    value={filters.q}
                    onApply={(q) => onApply({ ...filters, q })}
                    placeholder="Buscar por unidad o tipo…"
                    className="mr-1"
                />
                <SegmentedFilter
                    aria-label="Filtrar por severidad"
                    value={
                        filters.event_severity_id !== null
                            ? String(filters.event_severity_id)
                            : null
                    }
                    onChange={(value) =>
                        onApply({
                            ...filters,
                            event_severity_id:
                                value !== null ? Number(value) : null,
                        })
                    }
                    allLabel="Todas"
                    options={[...options.severities].reverse().map((o) => ({
                        value: o.value,
                        label: priorityLabel(o.code),
                        dot: SEVERITY_DOT[toSeverity(o.code)],
                    }))}
                />
                <FilterDropdown
                    label="Tipo"
                    value={
                        filters.event_type_id !== null
                            ? String(filters.event_type_id)
                            : null
                    }
                    options={options.eventTypes}
                    onChange={(value) =>
                        onApply({
                            ...filters,
                            event_type_id:
                                value !== null ? Number(value) : null,
                        })
                    }
                />
                <FilterDropdown
                    label="Categoría"
                    value={
                        filters.event_category_id !== null
                            ? String(filters.event_category_id)
                            : null
                    }
                    options={options.categories}
                    onChange={(value) =>
                        onApply({
                            ...filters,
                            event_category_id:
                                value !== null ? Number(value) : null,
                        })
                    }
                />
                <FilterDropdown
                    label="Pipeline"
                    value={filters.status}
                    options={options.statuses}
                    onChange={(status) => onApply({ ...filters, status })}
                />
                {hasActive && (
                    <ClearFiltersButton
                        onClick={() => onApply(EMPTY_EVENT_FILTERS)}
                    />
                )}
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                    Periodo
                </span>
                <div
                    role="group"
                    aria-label="Periodo rápido"
                    className="flex items-center gap-1"
                >
                    {QUICK_RANGES.map((range) => {
                        const active = quick === range.key;

                        return (
                            <button
                                key={range.key}
                                type="button"
                                aria-pressed={active}
                                onClick={() =>
                                    onApply({
                                        ...filters,
                                        occurred_from: active
                                            ? null
                                            : isoDaysAgo(range.days),
                                        occurred_until: null,
                                    })
                                }
                                className={cn(
                                    'rounded-full border px-2.5 py-1 text-2xs font-medium transition-colors',
                                    active
                                        ? 'border-primary/40 bg-primary/10 text-primary'
                                        : 'border-border bg-surface-1 text-fg-2 hover:border-border-strong hover:text-fg-1',
                                )}
                            >
                                {range.label}
                            </button>
                        );
                    })}
                </div>
                <span className="text-2xs text-fg-3">o</span>
                <input
                    type="date"
                    aria-label="Desde"
                    value={filters.occurred_from ?? ''}
                    onChange={(event) =>
                        onApply({
                            ...filters,
                            occurred_from:
                                event.target.value === ''
                                    ? null
                                    : event.target.value,
                        })
                    }
                    className="rounded-md border border-border bg-surface-1 px-2 py-1 text-xs text-fg-2"
                />
                <span className="text-2xs text-fg-3">→</span>
                <input
                    type="date"
                    aria-label="Hasta"
                    value={filters.occurred_until ?? ''}
                    onChange={(event) =>
                        onApply({
                            ...filters,
                            occurred_until:
                                event.target.value === ''
                                    ? null
                                    : event.target.value,
                        })
                    }
                    className="rounded-md border border-border bg-surface-1 px-2 py-1 text-xs text-fg-2"
                />
            </div>
        </div>
    );
}
