import { Head, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ScrollText, Search, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { DataTable } from '@/components/sam/data-table';
import type { DataTableColumn } from '@/components/sam/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';

interface AuditLogRow {
    id: number;
    action: string;
    category: string | null;
    actorType: string | null;
    actorId: number | null;
    entityType: string | null;
    entityId: number | null;
    summary: string | null;
    occurredAt: string | null;
}

interface DomainEventRow {
    id: number;
    eventName: string;
    aggregateType: string | null;
    aggregateId: number | null;
    correlationId: string | null;
    occurredAt: string | null;
}

interface AuditFilters {
    q: string | null;
    category: string | null;
    actor_type: string | null;
    from: string | null;
    to: string | null;
}

interface AuditPageProps {
    logs: AuditLogRow[];
    pagination: {
        page: number;
        perPage: number;
        total: number;
        lastPage: number;
    };
    filters: AuditFilters;
    filterOptions: {
        categories: string[];
        actorTypes: string[];
    };
    events: DomainEventRow[];
}

const TABS = [
    { key: 'logs', label: 'Auditoría' },
    { key: 'events', label: 'Eventos de dominio' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

const EMPTY_FILTERS: AuditFilters = {
    q: null,
    category: null,
    actor_type: null,
    from: null,
    to: null,
};

const LOG_COLUMNS: DataTableColumn<AuditLogRow>[] = [
    {
        key: 'occurredAt',
        header: 'Cuándo',
        sortValue: (log) =>
            log.occurredAt ? Date.parse(log.occurredAt) : null,
        cell: (log) => (
            <span className="font-mono text-2xs whitespace-nowrap text-fg-2">
                {log.occurredAt
                    ? new Date(log.occurredAt).toLocaleString('es')
                    : '—'}
            </span>
        ),
    },
    {
        key: 'action',
        header: 'Acción',
        sortValue: (log) => log.action,
        cell: (log) => (
            <span className="font-mono text-2xs text-fg-1">{log.action}</span>
        ),
    },
    {
        key: 'category',
        header: 'Categoría',
        sortValue: (log) => log.category,
        cell: (log) => (
            <Badge variant="outline" className="text-3xs text-fg-3">
                {log.category ?? '—'}
            </Badge>
        ),
    },
    {
        key: 'actor',
        header: 'Actor',
        sortValue: (log) => log.actorType,
        cell: (log) => (
            <span className="text-xs text-fg-2">
                {log.actorType ?? '—'}
                {log.actorId !== null && ` #${log.actorId}`}
            </span>
        ),
    },
    {
        key: 'entity',
        header: 'Entidad',
        cell: (log) => (
            <span className="font-mono text-2xs text-fg-2">
                {log.entityType ?? '—'}
                {log.entityId !== null && ` #${log.entityId}`}
            </span>
        ),
    },
    {
        key: 'summary',
        header: 'Resumen',
        cell: (log) => (
            <span
                className="block max-w-80 truncate text-xs text-fg-2"
                title={log.summary ?? ''}
            >
                {log.summary ?? '—'}
            </span>
        ),
    },
];

const EVENT_COLUMNS: DataTableColumn<DomainEventRow>[] = [
    {
        key: 'occurredAt',
        header: 'Cuándo',
        sortValue: (event) =>
            event.occurredAt ? Date.parse(event.occurredAt) : null,
        cell: (event) => (
            <span className="font-mono text-2xs whitespace-nowrap text-fg-2">
                {event.occurredAt
                    ? new Date(event.occurredAt).toLocaleString('es')
                    : '—'}
            </span>
        ),
    },
    {
        key: 'event',
        header: 'Evento',
        sortValue: (event) => event.eventName,
        cell: (event) => (
            <span className="font-mono text-2xs text-fg-1">
                {event.eventName}
            </span>
        ),
    },
    {
        key: 'aggregate',
        header: 'Agregado',
        sortValue: (event) => event.aggregateType,
        cell: (event) => (
            <span className="font-mono text-2xs text-fg-2">
                {event.aggregateType ?? '—'}
                {event.aggregateId !== null && ` #${event.aggregateId}`}
            </span>
        ),
    },
    {
        key: 'correlation',
        header: 'Correlación',
        cell: (event) => (
            <span className="font-mono text-2xs text-fg-2">
                {event.correlationId ?? '—'}
            </span>
        ),
    },
];

export default function AuditIndex() {
    const page = usePage();
    const { logs, pagination, filterOptions, events } =
        page.props as unknown as AuditPageProps;
    const serverFilters = (page.props as unknown as AuditPageProps).filters;

    const [tab, setTab] = useState<TabKey>('logs');
    const [filters, setFilters] = useState<AuditFilters>(serverFilters);
    const [search, setSearch] = useState(serverFilters.q ?? '');

    const applyFilters = useCallback((next: AuditFilters) => {
        setFilters(next);
        router.reload({
            only: ['logs', 'pagination', 'filters'],
            data: {
                q: next.q ?? undefined,
                category: next.category ?? undefined,
                actor_type: next.actor_type ?? undefined,
                from: next.from ?? undefined,
                to: next.to ?? undefined,
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
        router.reload({ only: ['logs', 'pagination'], data: { page: target } });
    }, []);

    const hasActive = Object.values(filters).some((value) => value !== null);

    return (
        <>
            <Head title="Auditoría" />
            <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
                <PageHeader
                    title="Auditoría"
                    description="Registro de acciones y eventos de dominio del tenant."
                    className="shrink-0 border-b border-border bg-background px-5 py-3"
                />

                <div className="flex shrink-0 gap-1 border-b border-border bg-background px-5">
                    {TABS.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => setTab(item.key)}
                            className={`px-3 py-2 text-sm transition-colors ${
                                tab === item.key
                                    ? 'border-b-2 border-primary font-medium text-fg-1'
                                    : 'text-fg-3 hover:text-fg-1'
                            }`}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>

                {tab === 'logs' && (
                    <>
                        <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-border bg-background px-5 py-2">
                            <div className="flex items-center gap-1.5 rounded-md border border-border bg-surface-1 px-2.5 py-1.5">
                                <Search size={12} className="text-fg-3" />
                                <input
                                    type="text"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Buscar acción, entidad…"
                                    className="w-48 border-none bg-transparent text-xs text-fg-1 outline-none"
                                />
                            </div>
                            <select
                                aria-label="Categoría"
                                value={filters.category ?? ''}
                                onChange={(event) =>
                                    applyFilters({
                                        ...filters,
                                        category:
                                            event.target.value === ''
                                                ? null
                                                : event.target.value,
                                    })
                                }
                                className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-xs text-fg-2"
                            >
                                <option value="">Categoría: todas</option>
                                {filterOptions.categories.map((category) => (
                                    <option key={category} value={category}>
                                        {category}
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label="Actor"
                                value={filters.actor_type ?? ''}
                                onChange={(event) =>
                                    applyFilters({
                                        ...filters,
                                        actor_type:
                                            event.target.value === ''
                                                ? null
                                                : event.target.value,
                                    })
                                }
                                className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-xs text-fg-2"
                            >
                                <option value="">Actor: todos</option>
                                {filterOptions.actorTypes.map((actor) => (
                                    <option key={actor} value={actor}>
                                        {actor}
                                    </option>
                                ))}
                            </select>
                            <input
                                type="date"
                                aria-label="Desde"
                                value={filters.from ?? ''}
                                onChange={(event) =>
                                    applyFilters({
                                        ...filters,
                                        from:
                                            event.target.value === ''
                                                ? null
                                                : event.target.value,
                                    })
                                }
                                className="rounded-md border border-border bg-surface-1 px-2 py-1 text-xs text-fg-2"
                            />
                            <input
                                type="date"
                                aria-label="Hasta"
                                value={filters.to ?? ''}
                                onChange={(event) =>
                                    applyFilters({
                                        ...filters,
                                        to:
                                            event.target.value === ''
                                                ? null
                                                : event.target.value,
                                    })
                                }
                                className="rounded-md border border-border bg-surface-1 px-2 py-1 text-xs text-fg-2"
                            />
                            {hasActive && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setSearch('');
                                        applyFilters(EMPTY_FILTERS);
                                    }}
                                    className="flex items-center gap-1 text-xs text-fg-3 hover:text-fg-1"
                                >
                                    <X size={11} />
                                    Limpiar
                                </button>
                            )}
                        </div>

                        <DataTable
                            columns={LOG_COLUMNS}
                            rows={logs}
                            rowKey={(log) => log.id}
                            empty={
                                <EmptyState
                                    className="min-h-0 flex-1"
                                    icon={ScrollText}
                                    title="Sin registros de auditoría."
                                    description="Cuando se registren acciones del tenant aparecerán aquí."
                                />
                            }
                        />

                        <div className="flex shrink-0 items-center justify-between border-t border-border bg-background px-5 py-2 text-xs text-fg-3">
                            <span>
                                {logs.length} de {pagination.total} registros
                            </span>
                            <div className="flex items-center gap-2">
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    disabled={pagination.page <= 1}
                                    onClick={() =>
                                        goToPage(pagination.page - 1)
                                    }
                                >
                                    <ChevronLeft size={13} />
                                </Button>
                                <span className="tabular-nums">
                                    {pagination.page} / {pagination.lastPage}
                                </span>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    disabled={
                                        pagination.page >= pagination.lastPage
                                    }
                                    onClick={() =>
                                        goToPage(pagination.page + 1)
                                    }
                                >
                                    <ChevronRight size={13} />
                                </Button>
                            </div>
                        </div>
                    </>
                )}

                {tab === 'events' && (
                    <DataTable
                        columns={EVENT_COLUMNS}
                        rows={events}
                        rowKey={(event) => event.id}
                        empty={
                            <EmptyState
                                className="min-h-0 flex-1"
                                icon={ScrollText}
                                title="Sin eventos de dominio registrados."
                                description="Cuando el sistema emita eventos de dominio aparecerán aquí."
                            />
                        }
                    />
                )}
            </div>
        </>
    );
}

AuditIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Auditoría',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/audit`
                : '/audit',
        },
    ],
});
