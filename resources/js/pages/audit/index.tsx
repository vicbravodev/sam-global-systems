import type { SharedPageProps } from '@inertiajs/core';
import { Head } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';
import { useState } from 'react';
import {
    AUDIT_LOG_COLUMNS,
    DOMAIN_EVENT_COLUMNS,
} from '@/components/sam/audit/audit-columns';
import {
    AuditFilterBar,
    EMPTY_AUDIT_FILTERS,
} from '@/components/sam/audit/audit-filter-bar';
import type {
    AuditFilterOptions,
    AuditFilters,
    AuditLogRow,
    DomainEventRow,
} from '@/components/sam/audit/types';
import { DataTable } from '@/components/sam/data-table';
import { ListFooter } from '@/components/sam/list';
import { ListPage } from '@/components/sam/list-page';
import { TabBar } from '@/components/sam/tab-bar';
import type { TabItem } from '@/components/sam/tab-bar';
import { EmptyState } from '@/components/ui/empty-state';
import { useServerList } from '@/hooks/use-server-list';
import auditRoutes from '@/routes/audit';
import type { ListPagination } from '@/types/pagination';

interface AuditPageProps {
    logs: AuditLogRow[];
    pagination: ListPagination;
    filters: AuditFilters;
    filterOptions: AuditFilterOptions;
    events: DomainEventRow[];
}

type TabKey = 'logs' | 'events';

const TABS: TabItem[] = [
    { key: 'logs', label: 'Auditoría' },
    { key: 'events', label: 'Eventos de dominio' },
];

export default function AuditIndex(pageProps: AuditPageProps) {
    const { logs, pagination, filterOptions, events } = pageProps;

    const [tab, setTab] = useState<TabKey>('logs');
    const list = useServerList({
        only: ['logs', 'pagination'],
        filters: pageProps.filters,
        emptyFilters: EMPTY_AUDIT_FILTERS,
    });

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
                        <AuditFilterBar
                            filters={list.filters}
                            filterOptions={filterOptions}
                            setFilter={list.setFilter}
                            apply={list.apply}
                        />

                        <DataTable
                            columns={AUDIT_LOG_COLUMNS}
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
                        columns={DOMAIN_EVENT_COLUMNS}
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

AuditIndex.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'Auditoría',
            href: props.currentTeam
                ? auditRoutes.show.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
