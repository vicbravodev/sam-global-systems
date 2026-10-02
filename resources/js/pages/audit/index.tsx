import { Head, usePage } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';
import { useState } from 'react';
import { DataTable } from '@/components/sam/data-table';
import type { DataTableColumn } from '@/components/sam/data-table';
import {
    ClearFiltersButton,
    ListFooter,
    SearchInput,
} from '@/components/sam/list';
import { ListPage } from '@/components/sam/list-page';
import { TabBar } from '@/components/sam/tab-bar';
import type { TabItem } from '@/components/sam/tab-bar';
import { Badge } from '@/components/ui/badge';
import { EmptyState } from '@/components/ui/empty-state';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useServerList } from '@/hooks/use-server-list';
import { formatDateTime } from '@/lib/format';
import type { ListPagination } from '@/types/pagination';

// Sentinel para representar "sin filtro" en los <Select> del DS: Radix no
// permite SelectItem con value="", así que el filtro vacío (todas/todos) se
// traduce a/desde este string en el handler.
const ALL_OPTION = '__all__';

interface AuditLogRow {
    id: number;
    action: string;
    actionLabel: string;
    category: string | null;
    categoryLabel: string | null;
    actorType: string | null;
    actorId: number | null;
    actorLabel: string | null;
    entityType: string | null;
    entityId: number | null;
    entityLabel: string | null;
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
    system: boolean;
}

interface FilterOption {
    value: string;
    label: string;
}

interface AuditPageProps {
    logs: AuditLogRow[];
    pagination: ListPagination;
    filters: AuditFilters;
    filterOptions: {
        categories: FilterOption[];
        actorTypes: FilterOption[];
    };
    events: DomainEventRow[];
}

type TabKey = 'logs' | 'events';

const TABS: TabItem[] = [
    { key: 'logs', label: 'Auditoría' },
    { key: 'events', label: 'Eventos de dominio' },
];

const EMPTY_FILTERS: AuditFilters = {
    q: null,
    category: null,
    actor_type: null,
    from: null,
    to: null,
    system: false,
};

const LOG_COLUMNS: DataTableColumn<AuditLogRow>[] = [
    {
        key: 'occurredAt',
        header: 'Cuándo',
        sortValue: (log) =>
            log.occurredAt ? Date.parse(log.occurredAt) : null,
        cell: (log) => (
            <span className="font-mono text-2xs whitespace-nowrap text-fg-2">
                {formatDateTime(log.occurredAt)}
            </span>
        ),
    },
    {
        key: 'action',
        header: 'Acción',
        sortValue: (log) => log.action,
        cell: (log) => (
            <span className="text-xs text-fg-1" title={log.action}>
                {log.actionLabel}
            </span>
        ),
    },
    {
        key: 'category',
        header: 'Categoría',
        sortValue: (log) => log.category,
        cell: (log) => (
            <Badge variant="outline" className="text-3xs text-fg-3">
                {log.categoryLabel ?? '—'}
            </Badge>
        ),
    },
    {
        key: 'actor',
        header: 'Actor',
        sortValue: (log) => log.actorLabel,
        cell: (log) => (
            <span className="text-xs text-fg-2">{log.actorLabel ?? '—'}</span>
        ),
    },
    {
        key: 'entity',
        header: 'Entidad',
        sortValue: (log) => log.entityLabel,
        cell: (log) => (
            <span className="text-xs text-fg-2">
                {log.entityLabel ?? '—'}
                {log.entityId !== null && ` #${log.entityId}`}
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
                {formatDateTime(event.occurredAt)}
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
    const pageProps = page.props as unknown as AuditPageProps;
    const { logs, pagination, filterOptions, events } = pageProps;

    const [tab, setTab] = useState<TabKey>('logs');
    const list = useServerList({
        only: ['logs', 'pagination'],
        filters: pageProps.filters,
        emptyFilters: EMPTY_FILTERS,
    });
    const { filters, apply } = list;

    // "Mostrar actividad del sistema" es una preferencia de vista, no un
    // filtro: ni cuenta para "Limpiar" ni se borra con él.
    const hasActive = (
        ['q', 'category', 'actor_type', 'from', 'to'] as const
    ).some((key) => filters[key] !== null);

    return (
        <>
            <Head title="Auditoría" />
            <ListPage
                title="Auditoría"
                description="Registro de acciones y eventos de dominio del tenant."
            >
                <TabBar
                    aria-label="Secciones de auditoría"
                    items={TABS}
                    value={tab}
                    onChange={(key) => setTab(key as TabKey)}
                    className="shrink-0 px-5"
                />

                {tab === 'logs' && (
                    <>
                        <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-border bg-background px-5 py-2">
                            <SearchInput
                                value={filters.q}
                                onApply={(q) => list.setFilter('q', q)}
                                placeholder="Buscar acción, entidad…"
                            />
                            <Select
                                value={filters.category ?? ALL_OPTION}
                                onValueChange={(value) =>
                                    list.setFilter(
                                        'category',
                                        value === ALL_OPTION ? null : value,
                                    )
                                }
                            >
                                <SelectTrigger
                                    aria-label="Categoría"
                                    className="h-9"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL_OPTION}>
                                        Categoría: todas
                                    </SelectItem>
                                    {filterOptions.categories.map(
                                        (category) => (
                                            <SelectItem
                                                key={category.value}
                                                value={category.value}
                                            >
                                                {category.label}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.actor_type ?? ALL_OPTION}
                                onValueChange={(value) =>
                                    list.setFilter(
                                        'actor_type',
                                        value === ALL_OPTION ? null : value,
                                    )
                                }
                            >
                                <SelectTrigger
                                    aria-label="Actor"
                                    className="h-9"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL_OPTION}>
                                        Actor: todos
                                    </SelectItem>
                                    {filterOptions.actorTypes.map((actor) => (
                                        <SelectItem
                                            key={actor.value}
                                            value={actor.value}
                                        >
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
                                    list.setFilter(
                                        'from',
                                        event.target.value === ''
                                            ? null
                                            : event.target.value,
                                    )
                                }
                                className="rounded-md border border-border bg-surface-1 px-2 py-1 text-xs text-fg-2"
                            />
                            <input
                                type="date"
                                aria-label="Hasta"
                                value={filters.to ?? ''}
                                onChange={(event) =>
                                    list.setFilter(
                                        'to',
                                        event.target.value === ''
                                            ? null
                                            : event.target.value,
                                    )
                                }
                                className="rounded-md border border-border bg-surface-1 px-2 py-1 text-xs text-fg-2"
                            />
                            <label className="flex cursor-pointer items-center gap-1.5 text-xs text-fg-2">
                                <input
                                    type="checkbox"
                                    checked={filters.system}
                                    onChange={(event) =>
                                        list.setFilter(
                                            'system',
                                            event.target.checked,
                                        )
                                    }
                                    className="accent-primary"
                                />
                                Mostrar actividad automática del sistema
                            </label>
                            {hasActive && (
                                <ClearFiltersButton
                                    onClick={() =>
                                        apply({
                                            ...EMPTY_FILTERS,
                                            system: filters.system,
                                        })
                                    }
                                />
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

                        <ListFooter
                            pagination={pagination}
                            shown={logs.length}
                            onPage={list.goToPage}
                            noun={['registro', 'registros']}
                        />
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
            </ListPage>
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
