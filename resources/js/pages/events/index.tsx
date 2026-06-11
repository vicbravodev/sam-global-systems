import { Head, router, usePage } from '@inertiajs/react';
import { Activity, ChevronLeft, ChevronRight, Search, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { DataTable } from '@/components/sam/data-table';
import type { DataTableColumn } from '@/components/sam/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';

export interface EventRow {
    id: number;
    occurredAt: string | null;
    status: string | null;
    eventType: string | null;
    eventTypeCode: string | null;
    category: string | null;
    severity: string | null;
    severityLabel: string | null;
    severityColor: string | null;
    asset: string | null;
    driver: string | null;
    provider: string | null;
}

export interface EventFilters {
    q: string | null;
    status: string | null;
    event_type_id: number | null;
    event_category_id: number | null;
    event_severity_id: number | null;
    occurred_from: string | null;
    occurred_until: string | null;
}

interface FilterOption {
    value: string;
    label: string;
}

interface EventsPageProps {
    events: EventRow[];
    pagination: {
        page: number;
        perPage: number;
        total: number;
        lastPage: number;
    };
    filters: EventFilters;
    filterOptions: {
        eventTypes: FilterOption[];
        categories: FilterOption[];
        severities: FilterOption[];
        statuses: FilterOption[];
    };
    unmappedCount: number;
}

const EMPTY_FILTERS: EventFilters = {
    q: null,
    status: null,
    event_type_id: null,
    event_category_id: null,
    event_severity_id: null,
    occurred_from: null,
    occurred_until: null,
};

const STATUS_BADGE: Record<string, string> = {
    normalized: 'text-fg-2',
    enrichment_pending: 'text-severity-medium',
    enriched: 'text-severity-low',
    failed: 'text-severity-critical',
    unmapped: 'text-severity-high',
};

const COLUMNS: DataTableColumn<EventRow>[] = [
    {
        key: 'occurredAt',
        header: 'Fecha',
        sortValue: (event) =>
            event.occurredAt ? Date.parse(event.occurredAt) : null,
        cell: (event) => (
            <span className="font-mono text-[11px] whitespace-nowrap text-fg-2">
                {event.occurredAt
                    ? new Date(event.occurredAt).toLocaleString('es')
                    : '—'}
            </span>
        ),
    },
    {
        key: 'type',
        header: 'Tipo',
        sortValue: (event) => event.eventType ?? event.eventTypeCode,
        cell: (event) => (
            <span className="text-[12px] text-fg-1">
                {event.eventType ?? event.eventTypeCode ?? '—'}
            </span>
        ),
    },
    {
        key: 'severity',
        header: 'Severidad',
        sortValue: (event) => event.severityLabel,
        cell: (event) =>
            event.severityLabel ? (
                <span
                    className="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase"
                    style={{
                        color: event.severityColor ?? undefined,
                        backgroundColor: event.severityColor
                            ? `${event.severityColor}22`
                            : undefined,
                    }}
                >
                    {event.severityLabel}
                </span>
            ) : (
                <span className="text-[12px] text-fg-2">—</span>
            ),
    },
    {
        key: 'asset',
        header: 'Activo',
        sortValue: (event) => event.asset,
        cell: (event) => (
            <span className="text-[12px] text-fg-2">{event.asset ?? '—'}</span>
        ),
    },
    {
        key: 'driver',
        header: 'Conductor',
        sortValue: (event) => event.driver,
        cell: (event) => (
            <span className="text-[12px] text-fg-2">{event.driver ?? '—'}</span>
        ),
    },
    {
        key: 'provider',
        header: 'Proveedor',
        cell: (event) => (
            <span className="text-[12px] text-fg-2">
                {event.provider ?? '—'}
            </span>
        ),
    },
    {
        key: 'status',
        header: 'Estado',
        sortValue: (event) => event.status,
        cell: (event) => (
            <span
                className={`text-[11px] ${STATUS_BADGE[event.status ?? ''] ?? 'text-fg-3'}`}
            >
                {event.status ?? '—'}
            </span>
        ),
    },
];

function FilterSelect({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string | null;
    options: FilterOption[];
    onChange: (value: string | null) => void;
}) {
    return (
        <select
            aria-label={label}
            value={value ?? ''}
            onChange={(event) =>
                onChange(event.target.value === '' ? null : event.target.value)
            }
            className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-[12px] text-fg-2"
        >
            <option value="">{label}: todos</option>
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    );
}

export default function EventsIndex() {
    const page = usePage();
    const { events, pagination, filterOptions, unmappedCount } =
        page.props as unknown as EventsPageProps;
    const serverFilters = (page.props as unknown as EventsPageProps).filters;

    const [filters, setFilters] = useState<EventFilters>(serverFilters);
    const [search, setSearch] = useState(serverFilters.q ?? '');
    const teamSlug =
        (
            page.props as unknown as {
                currentTeam?: { slug?: string | null } | null;
            }
        ).currentTeam?.slug ?? null;

    const applyFilters = useCallback((next: EventFilters) => {
        setFilters(next);
        router.reload({
            only: ['events', 'pagination', 'filters', 'unmappedCount'],
            data: {
                q: next.q ?? undefined,
                status: next.status ?? undefined,
                event_type_id: next.event_type_id ?? undefined,
                event_category_id: next.event_category_id ?? undefined,
                event_severity_id: next.event_severity_id ?? undefined,
                occurred_from: next.occurred_from ?? undefined,
                occurred_until: next.occurred_until ?? undefined,
                page: undefined,
            },
        });
    }, []);

    useEffect(() => {
        setSearch(serverFilters.q ?? '');

        setFilters(serverFilters);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [JSON.stringify(serverFilters)]);

    useEffect(() => {
        const current = filters.q ?? '';
        const next = search.trim();

        if (next === current) {
            return;
        }

        const timer = setTimeout(() => {
            applyFilters({ ...filters, q: next === '' ? null : next });
        }, 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const goToPage = useCallback((target: number) => {
        router.reload({
            only: ['events', 'pagination'],
            data: { page: target },
        });
    }, []);

    const hasActive = Object.values(filters).some((value) => value !== null);
    const unmappedActive = filters.status === 'unmapped';

    return (
        <>
            <Head title="Eventos" />
            <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
                {/* Head */}
                <PageHeader
                    title="Eventos"
                    description={`${pagination.total} eventos normalizados del pipeline`}
                    actions={
                        <Button
                            size="sm"
                            variant={unmappedActive ? 'default' : 'outline'}
                            onClick={() =>
                                applyFilters({
                                    ...EMPTY_FILTERS,
                                    status: unmappedActive ? null : 'unmapped',
                                })
                            }
                        >
                            Sin mapear
                            <Badge
                                variant="secondary"
                                className="ml-1 px-1.5 text-[10px]"
                            >
                                {unmappedCount}
                            </Badge>
                        </Button>
                    }
                    className="shrink-0 border-b border-border bg-background px-5 py-3"
                />

                {/* Filters */}
                <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-border bg-background px-5 py-2">
                    <div className="flex items-center gap-1.5 rounded-md border border-border bg-surface-1 px-2.5 py-1.5">
                        <Search size={12} className="text-fg-3" />
                        <input
                            type="text"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Buscar por activo o tipo…"
                            className="w-44 border-none bg-transparent text-[12px] text-fg-1 outline-none"
                        />
                    </div>
                    <FilterSelect
                        label="Tipo"
                        value={
                            filters.event_type_id !== null
                                ? String(filters.event_type_id)
                                : null
                        }
                        options={filterOptions.eventTypes}
                        onChange={(value) =>
                            applyFilters({
                                ...filters,
                                event_type_id:
                                    value !== null ? Number(value) : null,
                            })
                        }
                    />
                    <FilterSelect
                        label="Severidad"
                        value={
                            filters.event_severity_id !== null
                                ? String(filters.event_severity_id)
                                : null
                        }
                        options={filterOptions.severities}
                        onChange={(value) =>
                            applyFilters({
                                ...filters,
                                event_severity_id:
                                    value !== null ? Number(value) : null,
                            })
                        }
                    />
                    <FilterSelect
                        label="Categoría"
                        value={
                            filters.event_category_id !== null
                                ? String(filters.event_category_id)
                                : null
                        }
                        options={filterOptions.categories}
                        onChange={(value) =>
                            applyFilters({
                                ...filters,
                                event_category_id:
                                    value !== null ? Number(value) : null,
                            })
                        }
                    />
                    <FilterSelect
                        label="Estado"
                        value={filters.status}
                        options={filterOptions.statuses}
                        onChange={(value) =>
                            applyFilters({ ...filters, status: value })
                        }
                    />
                    <input
                        type="date"
                        aria-label="Desde"
                        value={filters.occurred_from ?? ''}
                        onChange={(event) =>
                            applyFilters({
                                ...filters,
                                occurred_from:
                                    event.target.value === ''
                                        ? null
                                        : event.target.value,
                            })
                        }
                        className="rounded-md border border-border bg-surface-1 px-2 py-1 text-[12px] text-fg-2"
                    />
                    <input
                        type="date"
                        aria-label="Hasta"
                        value={filters.occurred_until ?? ''}
                        onChange={(event) =>
                            applyFilters({
                                ...filters,
                                occurred_until:
                                    event.target.value === ''
                                        ? null
                                        : event.target.value,
                            })
                        }
                        className="rounded-md border border-border bg-surface-1 px-2 py-1 text-[12px] text-fg-2"
                    />
                    {hasActive && (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                applyFilters(EMPTY_FILTERS);
                            }}
                            className="flex items-center gap-1 text-[12px] text-fg-3 hover:text-fg-1"
                        >
                            <X size={11} />
                            Limpiar
                        </button>
                    )}
                </div>

                {/* Table */}
                <DataTable
                    columns={COLUMNS}
                    rows={events}
                    rowKey={(event) => event.id}
                    onRowClick={(event) => {
                        if (teamSlug) {
                            router.visit(`/${teamSlug}/events/${event.id}`);
                        }
                    }}
                    empty={
                        <EmptyState
                            className="min-h-0 flex-1"
                            icon={Activity}
                            title={
                                hasActive
                                    ? 'Sin eventos con estos filtros.'
                                    : 'Aún no hay eventos normalizados.'
                            }
                            description={
                                hasActive
                                    ? 'Ajusta o limpia los filtros para ver más eventos.'
                                    : 'Cuando el pipeline normalice eventos de tus integraciones aparecerán aquí.'
                            }
                        />
                    }
                />

                {/* Footer / pagination */}
                <div className="flex shrink-0 items-center justify-between border-t border-border bg-background px-5 py-2 text-[12px] text-fg-3">
                    <span>
                        {events.length} de {pagination.total} eventos
                    </span>
                    <div className="flex items-center gap-2">
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={pagination.page <= 1}
                            onClick={() => goToPage(pagination.page - 1)}
                        >
                            <ChevronLeft size={13} />
                        </Button>
                        <span className="tabular-nums">
                            {pagination.page} / {pagination.lastPage}
                        </span>
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={pagination.page >= pagination.lastPage}
                            onClick={() => goToPage(pagination.page + 1)}
                        >
                            <ChevronRight size={13} />
                        </Button>
                    </div>
                </div>
            </div>
        </>
    );
}

EventsIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Eventos',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/events`
                : '/events',
        },
    ],
});
