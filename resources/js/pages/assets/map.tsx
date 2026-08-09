import { Head, router, usePage } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { LiveMap } from '@/components/sam/assets/live-map';
import { RealtimeStatus } from '@/components/sam/realtime-status';
import type { RealtimeState } from '@/components/sam/realtime-status';
import { Button } from '@/components/ui/button';
import { useRealtimeConnection } from '@/hooks/use-realtime-connection';
import { TEAM_BROADCAST_EVENT_NAME } from '@/hooks/use-team-broadcasts';
import type { TeamBroadcastDetail } from '@/hooks/use-team-broadcasts';
import { cn } from '@/lib/utils';
import type {
    AssetMarker,
    AssetsMapProps,
    AssetStatusValue,
} from '@/types/assets';

// Reload (to pick up brand-new positioned assets) at most this often.
const RELOAD_DEBOUNCE_MS = 5000;

const LEGEND_DOTS: Record<AssetStatusValue, string> = {
    active: 'bg-severity-low',
    inactive: 'bg-fg-3',
    offline: 'bg-fg-3',
    alert: 'bg-severity-high',
    critical: 'bg-severity-critical',
    maintenance: 'bg-severity-medium',
};

function connectionToStatus(
    state: ReturnType<typeof useRealtimeConnection>,
): RealtimeState {
    switch (state) {
        case 'connected':
            return 'ok';
        case 'connecting':
        case 'reconnecting':
            return 'warn';
        default:
            return 'down';
    }
}

type LocationPayload = {
    asset_id: number;
    latitude: number;
    longitude: number;
    recorded_at: string;
};

type StatusPayload = {
    asset_id: number;
    new_status: string;
};

export default function AssetsMap() {
    const page = usePage();
    const pageProps = page.props as unknown as AssetsMapProps;
    const serverMarkers = useMemo(
        () => pageProps.assets ?? [],
        [pageProps.assets],
    );
    const unpositionedCount = pageProps.unpositionedCount ?? 0;
    const statusLabels = pageProps.statusLabels ?? {};
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const connection = useRealtimeConnection();

    const [markers, setMarkers] = useState<AssetMarker[]>(serverMarkers);
    const [refreshing, setRefreshing] = useState(false);

    // Re-sync in-memory markers whenever the server prop refreshes.
    useEffect(() => {
        setMarkers(serverMarkers);
    }, [serverMarkers]);

    const refresh = () => {
        setRefreshing(true);
        router.reload({
            only: ['assets', 'unpositionedCount'],
            onFinish: () => setRefreshing(false),
        });
    };

    // Live updates move markers IN MEMORY (no server roundtrip): that is the
    // whole point of the live map. A debounced partial reload only fires when
    // an unknown asset_id shows up (an asset just got its first position).
    const reloadTimer = useRef<number | null>(null);

    const scheduleReload = useCallback(() => {
        if (reloadTimer.current !== null) {
            return;
        }

        reloadTimer.current = window.setTimeout(() => {
            reloadTimer.current = null;
            router.reload({ only: ['assets', 'unpositionedCount'] });
        }, RELOAD_DEBOUNCE_MS);
    }, []);

    useEffect(() => {
        const handler = (event: Event) => {
            const detail = (event as CustomEvent<TeamBroadcastDetail>).detail;

            if (detail?.event === 'asset.location_updated') {
                const payload = detail.payload as unknown as LocationPayload;

                setMarkers((prev) => {
                    const known = prev.some((m) => m.id === payload.asset_id);

                    if (!known) {
                        scheduleReload();

                        return prev;
                    }

                    return prev.map((m) =>
                        m.id === payload.asset_id
                            ? {
                                  ...m,
                                  latitude: payload.latitude,
                                  longitude: payload.longitude,
                                  recordedAt: payload.recorded_at,
                              }
                            : m,
                    );
                });
            } else if (detail?.event === 'asset.status_changed') {
                const payload = detail.payload as unknown as StatusPayload;

                setMarkers((prev) =>
                    prev.map((m) =>
                        m.id === payload.asset_id
                            ? {
                                  ...m,
                                  status: payload.new_status as AssetStatusValue,
                              }
                            : m,
                    ),
                );
            }
        };

        window.addEventListener(TEAM_BROADCAST_EVENT_NAME, handler);

        return () => {
            window.removeEventListener(TEAM_BROADCAST_EVENT_NAME, handler);

            if (reloadTimer.current !== null) {
                window.clearTimeout(reloadTimer.current);
            }
        };
    }, [scheduleReload]);

    const handleSelect = useCallback(
        (id: number) => {
            if (teamSlug !== null) {
                router.visit(`/${teamSlug}/assets/${id}`);
            }
        },
        [teamSlug],
    );

    // Only legend entries for statuses present on the map.
    const presentStatuses = [...new Set(markers.map((m) => m.status))];

    return (
        <>
            <Head title="Mapa en vivo" />
            <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
                <header className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-border bg-surface-1 px-5 py-3">
                    <div className="flex items-center gap-3">
                        <h1 className="text-md font-semibold text-fg-1">
                            Mapa en vivo
                        </h1>
                        <span className="text-xs text-fg-3">
                            <span className="font-medium text-fg-1">
                                {markers.length}
                            </span>{' '}
                            {markers.length === 1
                                ? 'activo posicionado'
                                : 'activos posicionados'}
                            {unpositionedCount > 0 &&
                                ` · ${unpositionedCount} sin posición`}
                        </span>
                    </div>
                    <div className="flex items-center gap-3">
                        <div className="flex items-center gap-2.5">
                            {presentStatuses.map((status) => (
                                <span
                                    key={status}
                                    className="flex items-center gap-1 text-2xs text-fg-3"
                                >
                                    <span
                                        className={cn(
                                            'size-2 rounded-full',
                                            LEGEND_DOTS[status],
                                        )}
                                    />
                                    {statusLabels[status] ?? status}
                                </span>
                            ))}
                        </div>
                        <RealtimeStatus
                            state={connectionToStatus(connection)}
                        />
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={refresh}
                            disabled={refreshing}
                        >
                            <RefreshCw
                                size={13}
                                className={cn(refreshing && 'animate-spin')}
                            />
                            Refrescar
                        </Button>
                    </div>
                </header>

                <div className="relative min-h-0 flex-1">
                    <LiveMap
                        markers={markers}
                        statusLabels={statusLabels}
                        onSelect={handleSelect}
                    />
                    {markers.length === 0 && (
                        <div className="pointer-events-none absolute inset-x-0 top-4 z-10 flex justify-center">
                            <span className="rounded-md border border-border bg-surface-1/95 px-3 py-1.5 text-xs text-fg-2 shadow-sm">
                                Sin activos posicionados todavía: aparecerán
                                aquí cuando la sincronización registre
                                ubicaciones.
                            </span>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

AssetsMap.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Mapa en vivo',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/assets/map`
                : '/assets/map',
        },
    ],
});
