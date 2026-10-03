import type { SharedPageProps } from '@inertiajs/core';
import { Head, router, usePage } from '@inertiajs/react';
import { Activity } from 'lucide-react';
import { DataTable } from '@/components/sam/data-table';
import { EVENT_COLUMNS } from '@/components/sam/events/event-columns';
import { EventsFilterBar } from '@/components/sam/events/events-filter-bar';
import { EventsPulse } from '@/components/sam/events/events-pulse';
import {
    EMPTY_EVENT_FILTERS,
    EMPTY_EVENT_OPTIONS,
} from '@/components/sam/events/lib';
import { UnmappedButton } from '@/components/sam/events/unmapped-button';
import { EMPTY_PAGINATION, ListFooter } from '@/components/sam/list';
import { ListEmptyState, ListPage } from '@/components/sam/list-page';
import { useServerList } from '@/hooks/use-server-list';
import eventRoutes from '@/routes/events';
import type { EventsIndexProps } from '@/types/events';

export default function EventsIndex(pageProps: EventsIndexProps) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const events = pageProps.events ?? [];
    const pagination = pageProps.pagination ?? EMPTY_PAGINATION;
    const filterOptions = pageProps.filterOptions ?? EMPTY_EVENT_OPTIONS;
    const summary = pageProps.summary ?? null;
    const unmappedCount = pageProps.unmappedCount ?? 0;

    const list = useServerList({
        only: ['events', 'pagination'],
        applyOnly: ['events', 'pagination', 'filters', 'unmappedCount'],
        refreshOnly: ['events', 'pagination', 'summary', 'unmappedCount'],
        filters: pageProps.filters ?? EMPTY_EVENT_FILTERS,
        emptyFilters: EMPTY_EVENT_FILTERS,
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
                    <UnmappedButton
                        active={unmappedActive}
                        count={unmappedCount}
                        onToggle={() =>
                            list.apply({
                                ...EMPTY_EVENT_FILTERS,
                                status: unmappedActive ? null : 'unmapped',
                            })
                        }
                    />
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
                    <EventsFilterBar
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
                    columns={EVENT_COLUMNS}
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

EventsIndex.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'Eventos',
            href: props.currentTeam
                ? eventRoutes.index.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
