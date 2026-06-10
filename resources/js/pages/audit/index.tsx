import { Head, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Search, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

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
                <div className="shrink-0 border-b border-border bg-background px-5 py-3">
                    <h1 className="text-[16px] font-semibold text-fg-1">
                        Auditoría
                    </h1>
                    <p className="text-[12px] text-fg-3">
                        Registro de acciones y eventos de dominio del tenant.
                    </p>
                </div>

                <div className="flex shrink-0 gap-1 border-b border-border bg-background px-5">
                    {TABS.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => setTab(item.key)}
                            className={`px-3 py-2 text-[13px] transition-colors ${
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
                                    className="w-48 border-none bg-transparent text-[12px] text-fg-1 outline-none"
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
                                className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-[12px] text-fg-2"
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
                                className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-[12px] text-fg-2"
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
                                className="rounded-md border border-border bg-surface-1 px-2 py-1 text-[12px] text-fg-2"
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

                        <div className="min-h-0 flex-1 overflow-y-auto">
                            {logs.length === 0 ? (
                                <div className="flex h-40 items-center justify-center text-[13px] text-fg-3">
                                    Sin registros de auditoría.
                                </div>
                            ) : (
                                <table className="w-full text-left text-[12px]">
                                    <thead className="sticky top-0 bg-surface-1 text-[11px] tracking-[0.05em] text-fg-3 uppercase">
                                        <tr>
                                            <th className="px-5 py-2">
                                                Cuándo
                                            </th>
                                            <th className="px-3 py-2">
                                                Acción
                                            </th>
                                            <th className="px-3 py-2">
                                                Categoría
                                            </th>
                                            <th className="px-3 py-2">Actor</th>
                                            <th className="px-3 py-2">
                                                Entidad
                                            </th>
                                            <th className="px-3 py-2">
                                                Resumen
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {logs.map((log) => (
                                            <tr
                                                key={log.id}
                                                className="border-b border-border/50 text-fg-2"
                                            >
                                                <td className="px-5 py-2 font-mono text-[11px] whitespace-nowrap">
                                                    {log.occurredAt
                                                        ? new Date(
                                                              log.occurredAt,
                                                          ).toLocaleString('es')
                                                        : '—'}
                                                </td>
                                                <td className="px-3 py-2 font-mono text-[11px] text-fg-1">
                                                    {log.action}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <Badge
                                                        variant="outline"
                                                        className="text-[10px] text-fg-3"
                                                    >
                                                        {log.category ?? '—'}
                                                    </Badge>
                                                </td>
                                                <td className="px-3 py-2">
                                                    {log.actorType ?? '—'}
                                                    {log.actorId !== null &&
                                                        ` #${log.actorId}`}
                                                </td>
                                                <td className="px-3 py-2 font-mono text-[11px]">
                                                    {log.entityType ?? '—'}
                                                    {log.entityId !== null &&
                                                        ` #${log.entityId}`}
                                                </td>
                                                <td
                                                    className="max-w-80 truncate px-3 py-2"
                                                    title={log.summary ?? ''}
                                                >
                                                    {log.summary ?? '—'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>

                        <div className="flex shrink-0 items-center justify-between border-t border-border bg-background px-5 py-2 text-[12px] text-fg-3">
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
                    <div className="min-h-0 flex-1 overflow-y-auto">
                        {events.length === 0 ? (
                            <div className="flex h-40 items-center justify-center text-[13px] text-fg-3">
                                Sin eventos de dominio registrados.
                            </div>
                        ) : (
                            <table className="w-full text-left text-[12px]">
                                <thead className="sticky top-0 bg-surface-1 text-[11px] tracking-[0.05em] text-fg-3 uppercase">
                                    <tr>
                                        <th className="px-5 py-2">Cuándo</th>
                                        <th className="px-3 py-2">Evento</th>
                                        <th className="px-3 py-2">Agregado</th>
                                        <th className="px-3 py-2">
                                            Correlación
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {events.map((event) => (
                                        <tr
                                            key={event.id}
                                            className="border-b border-border/50 text-fg-2"
                                        >
                                            <td className="px-5 py-2 font-mono text-[11px] whitespace-nowrap">
                                                {event.occurredAt
                                                    ? new Date(
                                                          event.occurredAt,
                                                      ).toLocaleString('es')
                                                    : '—'}
                                            </td>
                                            <td className="px-3 py-2 font-mono text-[11px] text-fg-1">
                                                {event.eventName}
                                            </td>
                                            <td className="px-3 py-2 font-mono text-[11px]">
                                                {event.aggregateType ?? '—'}
                                                {event.aggregateId !== null &&
                                                    ` #${event.aggregateId}`}
                                            </td>
                                            <td className="px-3 py-2 font-mono text-[11px]">
                                                {event.correlationId ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
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
