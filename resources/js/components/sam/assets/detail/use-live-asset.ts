import { useEffect, useMemo, useRef, useState } from 'react';
import {
    useBroadcastReload,
    useTeamBroadcast,
} from '@/hooks/use-team-broadcasts';
import type { AssetShowProps, LocationTrailPoint } from '@/types/assets';
import type { FleetPosition } from '@/types/realtime';

const RELOAD_DEBOUNCE_MS = 2000;

// Every prop this page keeps live (what a socket resync reloads).
const LIVE_KEYS = ['asset', 'locationHistory', 'locationTrail', 'telemetry'];

// Live positions move the header and the map in memory every feed cycle; the
// history table (and the geocoded address) re-read the server at most this
// often while the unit reports.
const HISTORY_REFRESH_MS = 60_000;

/** The asset with a live position laid over it, when that one is newer. */
function withLivePosition(
    asset: AssetShowProps['asset'],
    live: FleetPosition | null,
): AssetShowProps['asset'] {
    if (
        live === null ||
        (asset.lastLocation !== null &&
            Date.parse(live.recorded_at) <=
                Date.parse(asset.lastLocation.recordedAt))
    ) {
        return asset;
    }

    return {
        ...asset,
        lastLocation: {
            latitude: live.latitude,
            longitude: live.longitude,
            formattedLocation: asset.lastLocation?.formattedLocation ?? null,
            speed: live.speed_kph,
            heading: live.heading,
            recordedAt: live.recorded_at,
        },
        currentSpeed:
            live.speed_kph === null
                ? asset.currentSpeed
                : {
                      kph: live.speed_kph,
                      recordedAt: live.recorded_at,
                      source: 'location',
                      stale: false,
                  },
        lastSignalAt: live.recorded_at,
    };
}

/**
 * The asset with its live position laid over, plus the trail extended with
 * the points received live. Listens to the team broadcasts for THIS unit and
 * coalesces bursts into one partial reload through the shared buffer.
 */
export function useLiveAsset(
    serverAsset: AssetShowProps['asset'],
    locationTrail: AssetShowProps['locationTrail'],
    trailWindowHours: AssetShowProps['trailWindowHours'],
): { asset: AssetShowProps['asset']; trail: LocationTrailPoint[] } {
    // Newest live position of THIS unit, and the points received live. The
    // trail below only uses the ones newer than the server's last point, so a
    // server reload supersedes them without resetting anything.
    const [live, setLive] = useState<FleetPosition | null>(null);
    const [liveTrail, setLiveTrail] = useState<LocationTrailPoint[]>([]);

    const asset = useMemo(
        () => withLivePosition(serverAsset, live),
        [serverAsset, live],
    );

    const trail = useMemo(() => {
        const server = locationTrail ?? [];
        const lastServer = server.at(-1);
        const lastServerAt = lastServer ? Date.parse(lastServer.recordedAt) : 0;

        return [
            ...server,
            ...liveTrail.filter(
                (point) => Date.parse(point.recordedAt) > lastServerAt,
            ),
        ];
    }, [locationTrail, liveTrail]);

    // Live updates for THIS asset only: location polls refresh position +
    // history, status transitions refresh the header badge. Bursts coalesce
    // in the shared reload buffer (one partial reload with the union of
    // affected props, paused while the tab is hidden); after a socket drop
    // every prop this page keeps live reloads once.
    const reload = useBroadcastReload(
        {},
        { debounceMs: RELOAD_DEBOUNCE_MS, resync: LIVE_KEYS },
    );
    const lastHistoryRefresh = useRef(0);

    // The page was just rendered from the server: no history refresh for the
    // first interval.
    useEffect(() => {
        lastHistoryRefresh.current = Date.now();
    }, [asset.id]);

    useTeamBroadcast(
        [
            'fleet.positions_updated',
            'fleet.telemetry_updated',
            'asset.location_updated',
            'asset.status_changed',
            'asset.monitoring_changed',
        ],
        (detail) => {
            switch (detail.event) {
                case 'fleet.positions_updated': {
                    const mine = detail.payload.positions.find(
                        (p) => p.asset_id === asset.id,
                    );

                    if (mine === undefined) {
                        return;
                    }

                    setLive(mine);
                    // Bounded to the trail window, however long the page
                    // stays open.
                    const cutoff =
                        Date.now() - (trailWindowHours ?? 2) * 3_600_000;
                    setLiveTrail((prev) => [
                        ...prev.filter(
                            (point) => Date.parse(point.recordedAt) > cutoff,
                        ),
                        {
                            latitude: mine.latitude,
                            longitude: mine.longitude,
                            recordedAt: mine.recorded_at,
                        },
                    ]);

                    if (
                        Date.now() - lastHistoryRefresh.current >
                        HISTORY_REFRESH_MS
                    ) {
                        lastHistoryRefresh.current = Date.now();
                        reload.schedule([
                            'asset',
                            'locationHistory',
                            'locationTrail',
                        ]);
                    }

                    return;
                }
                case 'fleet.telemetry_updated': {
                    if (
                        detail.payload.assets.some(
                            (entry) => entry.asset_id === asset.id,
                        )
                    ) {
                        reload.schedule(['telemetry']);
                    }

                    return;
                }
                case 'asset.location_updated': {
                    // A one-off live lookup (critical event) still arrives
                    // per asset.
                    if (detail.payload.asset_id === asset.id) {
                        reload.schedule(LIVE_KEYS);
                    }

                    return;
                }
                default: {
                    if (detail.payload.asset_id === asset.id) {
                        reload.schedule(['asset']);
                    }
                }
            }
        },
    );

    return { asset, trail };
}
