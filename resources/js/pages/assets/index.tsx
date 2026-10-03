import type { SharedPageProps } from '@inertiajs/core';
import { Deferred, Head, Link, router, usePage } from '@inertiajs/react';
import { Map as MapIcon, Truck } from 'lucide-react';
import { AssetsFilterBar } from '@/components/sam/assets/assets-filter-bar';
import { AssetsTable } from '@/components/sam/assets/assets-table';
import {
    FLEET_PULSE_LABELS,
    FleetPulse,
} from '@/components/sam/assets/fleet-pulse';
import { PendingBanner } from '@/components/sam/assets/pending-banner';
import { useLiveAssetRows } from '@/components/sam/assets/use-live-asset-rows';
import { useMonitorAllPending } from '@/components/sam/assets/use-monitor-all-pending';
import { EMPTY_PAGINATION, ListFooter } from '@/components/sam/list';
import { ListEmptyState, ListPage } from '@/components/sam/list-page';
import { PulseStripSkeleton } from '@/components/sam/pulse-strip';
import { Button } from '@/components/ui/button';
import { useServerList } from '@/hooks/use-server-list';
import assetRoutes from '@/routes/assets';
import type {
    AssetFilterOptions,
    AssetFilters,
    AssetsIndexProps,
} from '@/types/assets';

const EMPTY_FILTERS: AssetFilters = {
    q: null,
    status: null,
    type: null,
    monitoring: null,
};

const EMPTY_OPTIONS: AssetFilterOptions = {
    statuses: [],
    types: [],
    monitoring: [],
};

export default function AssetsIndex(pageProps: AssetsIndexProps) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const pagination = pageProps.pagination ?? EMPTY_PAGINATION;
    const filterOptions = pageProps.filterOptions ?? EMPTY_OPTIONS;
    const summary = pageProps.summary ?? null;
    const monitoring = pageProps.monitoring ?? null;
    const assets = useLiveAssetRows(pageProps.assets);
    const { monitoringAll, monitorAllPending } = useMonitorAllPending(teamSlug);

    const list = useServerList({
        only: ['assets', 'pagination'],
        refreshOnly: ['assets', 'pagination', 'summary', 'monitoring'],
        filters: pageProps.filters ?? EMPTY_FILTERS,
        emptyFilters: EMPTY_FILTERS,
    });

    const handleSelect = (id: number) => {
        if (teamSlug !== null) {
            router.visit(assetRoutes.show([teamSlug, id]));
        }
    };

    const total = summary?.total ?? pagination.total;

    return (
        <>
            <Head title="Flota" />
            <ListPage
                title="Flota"
                meta={
                    <span className="text-xs text-fg-3">
                        <span className="font-medium text-fg-1">{total}</span>{' '}
                        {total === 1 ? 'unidad' : 'unidades'}
                        {summary && (
                            <>
                                {' · '}
                                <span className="text-severity-low">
                                    {summary.reporting} reportando ahora
                                </span>
                            </>
                        )}
                    </span>
                }
                actions={
                    teamSlug && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={assetRoutes.map(teamSlug)}>
                                <MapIcon size={13} />
                                Mapa en vivo
                            </Link>
                        </Button>
                    )
                }
                onRefresh={list.refresh}
                refreshing={list.refreshing}
                pulse={
                    <>
                        {monitoring && (
                            <PendingBanner
                                monitoring={monitoring}
                                teamSlug={teamSlug}
                                busy={monitoringAll}
                                onShowPending={() =>
                                    list.setFilter('monitoring', 'pending')
                                }
                                onMonitorAll={monitorAllPending}
                            />
                        )}
                        <Deferred
                            data={['summary', 'monitoring']}
                            fallback={
                                <PulseStripSkeleton
                                    labels={FLEET_PULSE_LABELS}
                                />
                            }
                        >
                            {summary && (
                                <FleetPulse
                                    summary={summary}
                                    monitoring={monitoring}
                                    status={list.filters.status}
                                    monitoringFilter={list.filters.monitoring}
                                    onStatus={(status) =>
                                        list.setFilter('status', status)
                                    }
                                    onMonitoring={(value) =>
                                        list.setFilter('monitoring', value)
                                    }
                                />
                            )}
                        </Deferred>
                    </>
                }
                filters={
                    <AssetsFilterBar
                        filters={list.filters}
                        options={filterOptions}
                        summary={summary}
                        onApply={list.apply}
                    />
                }
                footer={
                    <ListFooter
                        pagination={pagination}
                        shown={assets.length}
                        onPage={list.goToPage}
                        noun={['unidad', 'unidades']}
                    />
                }
            >
                <AssetsTable
                    rows={assets}
                    onSelect={handleSelect}
                    teamSlug={teamSlug}
                    empty={
                        <ListEmptyState
                            icon={Truck}
                            filtered={list.hasActiveFilters}
                            title="Sin unidades"
                            description="Cuando la sincronización de integraciones registre vehículos aparecerán aquí."
                            filteredDescription="Ninguna unidad coincide con los filtros aplicados."
                        />
                    }
                />
            </ListPage>
        </>
    );
}

AssetsIndex.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'Flota',
            href: props.currentTeam
                ? assetRoutes.index.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
