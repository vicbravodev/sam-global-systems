import { Head, router, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CircleSlash,
    ShieldAlert,
    Sparkles,
    Truck,
    Unlink,
} from 'lucide-react';
import { CellEmpty, DataTable } from '@/components/sam/data-table';
import type { DataTableColumn } from '@/components/sam/data-table';
import { EntityAvatar } from '@/components/sam/entity-avatar';
import { SEVERITY_DOT, toSeverity } from '@/components/sam/event-severity';
import { EventCategoryIcon } from '@/components/sam/events/event-category-icon';
import { PipelineStatusPill } from '@/components/sam/events/pipeline-status';
import {
    ClearFiltersButton,
    EMPTY_PAGINATION,
    FilterDropdown,
    ListFooter,
    SearchInput,
} from '@/components/sam/list';
import { ListEmptyState, ListPage } from '@/components/sam/list-page';
import { ProviderTag } from '@/components/sam/provider-tag';
import { PulseStat, PulseStrip } from '@/components/sam/pulse-strip';
import { RelativeTime } from '@/components/sam/relative-time';
import { SegmentedFilter } from '@/components/sam/segmented-filter';
import { SeverityBadge } from '@/components/sam/severity-badge';
import { Button } from '@/components/ui/button';
import { useServerList } from '@/hooks/use-server-list';
import { formatDateTime } from '@/lib/format';
import { priorityLabel, providerDescriptionLabel } from '@/lib/labels';
import { formatClock, dayLabel, minutesSince } from '@/lib/time';
import { cn } from '@/lib/utils';
import eventRoutes from '@/routes/events';
import type {
    EventFilterOptions,
    EventFilters,
    EventRow,
    EventsIndexProps,
    EventsSummary,
} from '@/types/events';

const EMPTY_FILTERS: EventFilters = {
    q: null,
    status: null,
    event_type_id: null,
    event_category_id: null,
    event_severity_id: null,
    occurred_from: null,
    occurred_until: null,
};

const EMPTY_OPTIONS: EventFilterOptions = {
    eventTypes: [],
    categories: [],
    severities: [],
    statuses: [],
};

// ---- Quick date ranges ----

type QuickRange = 'today' | '7d' | '30d';

function isoDaysAgo(days: number): string {
    const date = new Date();
    date.setDate(date.getDate() - days);

    return date.toISOString().slice(0, 10);
}

const QUICK_RANGES: { key: QuickRange; label: string; days: number }[] = [
    { key: 'today', label: 'Hoy', days: 0 },
    { key: '7d', label: '7 días', days: 6 },
    { key: '30d', label: '30 días', days: 29 },
];

function activeQuickRange(filters: EventFilters): QuickRange | null {
    if (filters.occurred_until !== null) {
        return null;
    }

    return (
        QUICK_RANGES.find((r) => filters.occurred_from === isoDaysAgo(r.days))
            ?.key ?? null
    );
}

// ---- Columns ----

function WhenCell({ iso }: { iso: string | null }) {
    if (iso === null) {
        return <CellEmpty />;
    }

    return (
        <span
            className="flex flex-col whitespace-nowrap"
            title={formatDateTime(iso)}
        >
            <RelativeTime minutes={minutesSince(iso)} className="text-fg-1" />
            <span className="font-mono text-3xs text-fg-3 tabular-nums">
                {dayLabel(iso)} · {formatClock(iso)}
            </span>
        </span>
    );
}

function EventCell({ event }: { event: EventRow }) {
    return (
        <span className="flex items-center gap-2.5">
            <span className="grid size-7 shrink-0 place-items-center rounded-md border border-border bg-surface-2">
                <EventCategoryIcon code={event.categoryCode} />
            </span>
            <span className="flex min-w-0 flex-col">
                <span className="truncate text-sm font-medium text-fg-1">
                    {event.eventType ??
                        event.eventTypeCode ??
                        `Evento #${event.id}`}
                </span>
                <span className="truncate text-3xs text-fg-3">
                    {[
                        event.category,
                        providerDescriptionLabel(
                            event.description,
                            event.eventType,
                        ),
                    ]
                        .filter(Boolean)
                        .join(' · ') || '—'}
                </span>
            </span>
        </span>
    );
}

function PipelineCell({ event }: { event: EventRow }) {
    return (
        <span className="flex items-center gap-1.5">
            <PipelineStatusPill
                status={event.status}
                label={event.statusLabel}
            />
            {event.hasEvaluation && (
                <span
                    title="Evaluado por IA"
                    className="grid size-5 place-items-center rounded-sm border border-ai-accent/40 bg-ai-accent-bg text-ai-accent"
                >
                    <Sparkles size={11} aria-label="Evaluado por IA" />
                </span>
            )}
            {event.hasIncident && (
                <span
                    title="Abrió un incidente"
                    className="grid size-5 place-items-center rounded-sm border border-severity-high/40 bg-severity-high/10 text-severity-high"
                >
                    <ShieldAlert size={11} aria-label="Abrió un incidente" />
                </span>
            )}
        </span>
    );
}

const COLUMNS: DataTableColumn<EventRow>[] = [
    {
        key: 'occurredAt',
        header: 'Cuándo',
        width: 'w-36',
        sortValue: (event) =>
            event.occurredAt ? Date.parse(event.occurredAt) : null,
        cell: (event) => <WhenCell iso={event.occurredAt} />,
    },
    {
        key: 'event',
        header: 'Evento',
        sortValue: (event) => event.eventType ?? event.eventTypeCode,
        cell: (event) => <EventCell event={event} />,
    },
    {
        key: 'severity',
        header: 'Severidad',
        width: 'w-28',
        sortValue: (event) => event.severityLabel,
        cell: (event) =>
            event.severity ? (
                <SeverityBadge level={toSeverity(event.severity)} />
            ) : (
                <CellEmpty />
            ),
    },
    {
        key: 'asset',
        header: 'Unidad',
        width: 'w-40',
        sortValue: (event) => event.asset,
        cell: (event) =>
            event.asset ? (
                <span className="flex items-center gap-1.5 text-xs text-fg-1">
                    <Truck
                        size={12}
                        strokeWidth={1.75}
                        className="shrink-0 text-fg-3"
                        aria-hidden="true"
                    />
                    <span className="truncate">{event.asset}</span>
                </span>
            ) : (
                <CellEmpty />
            ),
    },
    {
        key: 'driver',
        header: 'Conductor',
        width: 'w-44',
        sortValue: (event) => event.driver,
        cell: (event) =>
            event.driver ? (
                <span className="flex items-center gap-2">
                    <EntityAvatar name={event.driver} size={20} />
                    <span className="truncate text-xs text-fg-1">
                        {event.driver}
                    </span>
                </span>
            ) : (
                <CellEmpty variant="person" />
            ),
    },
    {
        key: 'provider',
        header: 'Origen',
        width: 'w-24',
        cell: (event) =>
            event.provider ? (
                <ProviderTag name={event.provider} />
            ) : (
                <span className="text-2xs text-fg-3">interno</span>
            ),
    },
    {
        key: 'status',
        header: 'Pipeline',
        width: 'w-44',
        sortValue: (event) => event.status,
        cell: (event) => <PipelineCell event={event} />,
    },
];

// ---- Pulse strip ----

function EventsPulse({
    summary,
    filters,
    onApply,
}: {
    summary: EventsSummary;
    filters: EventFilters;
    onApply: (next: EventFilters) => void;
}) {
    const toggleStatus = (value: string) => () =>
        onApply({
            ...filters,
            status: filters.status === value ? null : value,
        });

    return (
        <PulseStrip>
            <PulseStat
                label="Últimas 24 h"
                value={summary.last24h}
                icon={Activity}
                tone="info"
                live={summary.last24h > 0}
                hint="eventos normalizados"
            />
            <PulseStat
                label="Severos 24 h"
                value={summary.severe24h}
                icon={AlertTriangle}
                tone={summary.severe24h > 0 ? 'critical' : 'neutral'}
                hint="severidad alta o crítica"
            />
            <PulseStat
                label="Con incidente 24 h"
                value={summary.incidents24h}
                icon={ShieldAlert}
                tone={summary.incidents24h > 0 ? 'warn' : 'neutral'}
                hint="escalados a la bandeja"
            />
            <PulseStat
                label="Sin mapear"
                value={summary.unmapped}
                icon={Unlink}
                tone={summary.unmapped > 0 ? 'warn' : 'neutral'}
                hint="sin regla de normalización"
                onClick={toggleStatus('unmapped')}
                active={filters.status === 'unmapped'}
            />
            <PulseStat
                label="Fallidos"
                value={summary.failed}
                icon={CircleSlash}
                tone={summary.failed > 0 ? 'critical' : 'neutral'}
                hint="error en el pipeline"
                onClick={toggleStatus('failed')}
                active={filters.status === 'failed'}
            />
        </PulseStrip>
    );
}

// ---- FilterBar ----

function FilterBar({
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
                        onClick={() => onApply(EMPTY_FILTERS)}
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

// ---- Page ----

export default function EventsIndex() {
    const page = usePage();
    const pageProps = page.props as unknown as EventsIndexProps;
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const events = pageProps.events ?? [];
    const pagination = pageProps.pagination ?? EMPTY_PAGINATION;
    const filterOptions = pageProps.filterOptions ?? EMPTY_OPTIONS;
    const summary = pageProps.summary ?? null;
    const unmappedCount = pageProps.unmappedCount ?? 0;

    const list = useServerList({
        only: ['events', 'pagination'],
        applyOnly: ['events', 'pagination', 'filters', 'unmappedCount'],
        refreshOnly: ['events', 'pagination', 'summary', 'unmappedCount'],
        filters: pageProps.filters ?? EMPTY_FILTERS,
        emptyFilters: EMPTY_FILTERS,
    });

    const hasActive = list.hasActiveFilters;
    const unmappedActive = list.filters.status === 'unmapped';

    return (
        <>
            <Head title="Eventos" />
            <ListPage
                title="Eventos"
                meta={
                    <span className="text-xs text-fg-3">
                        <span className="font-medium text-fg-1">
                            {pagination.total}
                        </span>{' '}
                        {hasActive
                            ? 'con estos filtros'
                            : 'eventos normalizados'}
                        {summary && summary.last24h > 0 && (
                            <>
                                {' · '}
                                <span className="text-severity-info">
                                    {summary.last24h} en 24 h
                                </span>
                            </>
                        )}
                    </span>
                }
                actions={
                    <Button
                        size="sm"
                        variant={unmappedActive ? 'default' : 'outline'}
                        onClick={() =>
                            list.apply({
                                ...EMPTY_FILTERS,
                                status: unmappedActive ? null : 'unmapped',
                            })
                        }
                    >
                        <Unlink size={13} />
                        Sin mapear
                        <span
                            className={cn(
                                'rounded-full px-1.5 font-mono text-3xs tabular-nums',
                                unmappedActive
                                    ? 'bg-primary-foreground/20'
                                    : unmappedCount > 0
                                      ? 'bg-severity-high/15 text-severity-high'
                                      : 'bg-surface-3 text-fg-3',
                            )}
                        >
                            {unmappedCount}
                        </span>
                    </Button>
                }
                onRefresh={list.refresh}
                refreshing={list.refreshing}
                pulse={
                    summary && (
                        <EventsPulse
                            summary={summary}
                            filters={list.filters}
                            onApply={list.apply}
                        />
                    )
                }
                filters={
                    <FilterBar
                        filters={list.filters}
                        options={filterOptions}
                        onApply={list.apply}
                    />
                }
                footer={
                    <ListFooter
                        pagination={pagination}
                        shown={events.length}
                        onPage={list.goToPage}
                        noun={['evento', 'eventos']}
                    />
                }
            >
                <DataTable
                    columns={COLUMNS}
                    rows={events}
                    rowKey={(event) => event.id}
                    onRowClick={(event) => {
                        if (teamSlug) {
                            router.visit(
                                eventRoutes.show([teamSlug, event.id]),
                            );
                        }
                    }}
                    empty={
                        <ListEmptyState
                            icon={Activity}
                            filtered={hasActive}
                            title="Aún no hay eventos normalizados."
                            description="Cuando el pipeline normalice eventos de tus integraciones aparecerán aquí."
                            filteredTitle="Sin eventos con estos filtros."
                            filteredDescription="Ajusta o limpia los filtros para ver más eventos."
                        />
                    }
                />
            </ListPage>
        </>
    );
}

EventsIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Eventos',
            href: props.currentTeam
                ? eventRoutes.index.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
