import type { SharedPageProps } from '@inertiajs/core';
import { Head, usePage } from '@inertiajs/react';
import { lazy, Suspense, useMemo } from 'react';
import { LiveMapHeader } from '@/components/sam/assets/map/live-map-header';
import { StatusChips } from '@/components/sam/assets/map/status-chips';
import { UnitCallout } from '@/components/sam/assets/map/unit-callout';
import { UnitRoster } from '@/components/sam/assets/map/unit-roster';
import { useLiveMarkers } from '@/components/sam/assets/map/use-live-markers';
import { useMapSelection } from '@/components/sam/assets/map/use-map-selection';
import { useRosterFilter } from '@/components/sam/assets/map/use-roster-filter';
import { MapLoading } from '@/components/sam/map/map-controls';
import assetRoutes from '@/routes/assets';
import type { AssetsMapProps } from '@/types/assets';

// maplibre-gl loads in its own chunk: the header and the roster paint first,
// the map area shows its loading frame meanwhile.
const LiveMap = lazy(() =>
    import('@/components/sam/assets/live-map').then((module) => ({
        default: module.LiveMap,
    })),
);

export default function AssetsMap(pageProps: AssetsMapProps) {
    const page = usePage();
    const serverMarkers = useMemo(
        () => pageProps.assets ?? [],
        [pageProps.assets],
    );
    const unpositionedCount = pageProps.unpositionedCount ?? 0;
    const statusLabels = useMemo(
        () => pageProps.statusLabels ?? {},
        [pageProps.statusLabels],
    );
    const teamSlug = page.props.currentTeam?.slug ?? null;

    const markers = useLiveMarkers(serverMarkers);
    const roster = useRosterFilter(markers);
    const { selectedId, setSelectedId, focusRequest, pickFromList } =
        useMapSelection(roster.visible);

    const statusChips = (
        <StatusChips
            statuses={roster.presentStatuses}
            hidden={roster.hidden}
            counts={roster.counts}
            statusLabels={statusLabels}
            onToggle={roster.toggleStatus}
        />
    );

    return (
        <>
            <Head title="Mapa en vivo" />
            <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
                <LiveMapHeader
                    unitCount={markers.length}
                    movingCount={roster.moving}
                    unpositionedCount={unpositionedCount}
                    newestAt={roster.newestAt}
                />

                <div className="flex min-h-0 flex-1">
                    <UnitRoster
                        listed={roster.listed}
                        hasMarkers={markers.length > 0}
                        selectedId={selectedId}
                        statusLabels={statusLabels}
                        query={roster.query}
                        onQueryChange={roster.setQuery}
                        filtering={roster.filtering}
                        onClearFilters={roster.clear}
                        onPick={pickFromList}
                        statusChips={statusChips}
                    />

                    <div className="relative min-h-0 min-w-0 flex-1">
                        <Suspense
                            fallback={
                                <div className="relative h-full w-full overflow-hidden bg-surface-2">
                                    <MapLoading />
                                </div>
                            }
                        >
                            <LiveMap
                                markers={roster.visible}
                                statusLabels={statusLabels}
                                selectedId={selectedId}
                                onSelect={setSelectedId}
                                focusRequest={focusRequest}
                                renderCallout={(asset) => (
                                    <UnitCallout
                                        asset={asset}
                                        statusLabels={statusLabels}
                                        teamSlug={teamSlug}
                                        onClose={() => setSelectedId(null)}
                                    />
                                )}
                            />
                        </Suspense>

                        {/* Small screens: the roster is hidden, the status
                            filter floats on the map. */}
                        {roster.presentStatuses.length > 0 && (
                            <div className="pointer-events-auto absolute top-3 left-3 z-10 max-w-[calc(100%-4.5rem)] lg:hidden">
                                {statusChips}
                            </div>
                        )}

                        {markers.length === 0 && (
                            <div className="pointer-events-none absolute inset-x-0 bottom-6 z-10 flex justify-center px-4">
                                <span className="max-w-md rounded-md border border-border bg-surface-1 px-3 py-2 text-center text-xs text-fg-2 shadow-sm">
                                    Sin unidades posicionadas todavía.
                                    Aparecerán aquí en cuanto la integración
                                    registre su primera ubicación.
                                </span>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

AssetsMap.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'Mapa en vivo',
            href: props.currentTeam
                ? assetRoutes.map.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
