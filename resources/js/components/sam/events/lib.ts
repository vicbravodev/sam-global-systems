import type { EventFilterOptions, EventFilters } from '@/types/events';

export const EMPTY_EVENT_FILTERS: EventFilters = {
    q: null,
    status: null,
    event_type_id: null,
    event_category_id: null,
    event_severity_id: null,
    occurred_from: null,
    occurred_until: null,
};

export const EMPTY_EVENT_OPTIONS: EventFilterOptions = {
    eventTypes: [],
    categories: [],
    severities: [],
    statuses: [],
};

// ---- Quick date ranges ----

type QuickRange = 'today' | '7d' | '30d';

export function isoDaysAgo(days: number): string {
    const date = new Date();
    date.setDate(date.getDate() - days);

    return date.toISOString().slice(0, 10);
}

export const QUICK_RANGES: { key: QuickRange; label: string; days: number }[] =
    [
        { key: 'today', label: 'Hoy', days: 0 },
        { key: '7d', label: '7 días', days: 6 },
        { key: '30d', label: '30 días', days: 29 },
    ];

export function activeQuickRange(filters: EventFilters): QuickRange | null {
    if (filters.occurred_until !== null) {
        return null;
    }

    return (
        QUICK_RANGES.find((r) => filters.occurred_from === isoDaysAgo(r.days))
            ?.key ?? null
    );
}
