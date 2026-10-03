import type { SharedPageProps } from '@inertiajs/core';
import { Head, router, usePage } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { DriversFilterBar } from '@/components/sam/drivers/drivers-filter-bar';
import { DriversPulse } from '@/components/sam/drivers/drivers-pulse';
import { DriversTable } from '@/components/sam/drivers/drivers-table';
import { EMPTY_PAGINATION, ListFooter } from '@/components/sam/list';
import { ListEmptyState, ListPage } from '@/components/sam/list-page';
import { useServerList } from '@/hooks/use-server-list';
import driverRoutes from '@/routes/drivers';
import type {
    DriverFilterOptions,
    DriverFilters,
    DriversIndexProps,
} from '@/types/drivers';

const EMPTY_FILTERS: DriverFilters = { q: null, status: null };

const EMPTY_OPTIONS: DriverFilterOptions = { statuses: [] };

export default function DriversIndex(pageProps: DriversIndexProps) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const drivers = pageProps.drivers ?? [];
    const pagination = pageProps.pagination ?? EMPTY_PAGINATION;
    const filterOptions = pageProps.filterOptions ?? EMPTY_OPTIONS;
    const summary = pageProps.summary ?? null;

    const list = useServerList({
        only: ['drivers', 'pagination'],
        refreshOnly: ['drivers', 'pagination', 'summary'],
        filters: pageProps.filters ?? EMPTY_FILTERS,
        emptyFilters: EMPTY_FILTERS,
    });

    const handleSelect = (id: number) => {
        if (teamSlug !== null) {
            router.visit(driverRoutes.show([teamSlug, id]));
        }
    };

    const total = summary?.total ?? pagination.total;

    return (
        <>
            <Head title="Conductores" />
            <ListPage
                title="Conductores"
                meta={
                    <span className="text-xs text-fg-3">
                        <span className="font-medium text-fg-1">{total}</span>{' '}
                        {total === 1 ? 'conductor' : 'conductores'}
                        {summary && (
                            <>
                                {' · '}
                                <span className="text-severity-low">
                                    {summary.statuses.active} en servicio
                                </span>
                            </>
                        )}
                    </span>
                }
                onRefresh={list.refresh}
                refreshing={list.refreshing}
                pulse={
                    summary && (
                        <DriversPulse
                            summary={summary}
                            status={list.filters.status}
                            onStatus={(status) =>
                                list.setFilter('status', status)
                            }
                        />
                    )
                }
                filters={
                    <DriversFilterBar
                        filters={list.filters}
                        options={filterOptions}
                        summary={summary}
                        onApply={list.apply}
                    />
                }
                footer={
                    <ListFooter
                        pagination={pagination}
                        shown={drivers.length}
                        onPage={list.goToPage}
                        noun={['conductor', 'conductores']}
                    />
                }
            >
                <DriversTable
                    rows={drivers}
                    onSelect={handleSelect}
                    empty={
                        <ListEmptyState
                            icon={Users}
                            filtered={list.hasActiveFilters}
                            title="Sin conductores"
                            description="Cuando la sincronización de integraciones registre conductores aparecerán aquí."
                            filteredDescription="Ningún conductor coincide con los filtros aplicados."
                        />
                    }
                    presence={pageProps.columns}
                />
            </ListPage>
        </>
    );
}

DriversIndex.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'Conductores',
            href: props.currentTeam
                ? driverRoutes.index.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
