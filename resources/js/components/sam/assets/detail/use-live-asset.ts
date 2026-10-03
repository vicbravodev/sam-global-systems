import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { TEAM_BROADCAST_EVENT_NAME } from '@/hooks/use-team-broadcasts';
import type { TeamBroadcastDetail } from '@/hooks/use-team-broadcasts';
import type { AssetShowProps, LocationTrailPoint } from '@/types/assets';
import type {
    FleetPosition,
    FleetPositionsUpdatedPayload,
    FleetTelemetryUpdatedPayload,
} from '@/types/realtime';

const RELOAD_DEBOUNCE_MS = 2000;

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
 * coalesces bursts into one partial reload.
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
        const lastServerAt =
            server.length > 0
                ? Date.parse(server[server.length - 1].recordedAt)
                : 0;

        return [
            ...server,
            ...liveTrail.filter(
                (point) => Date.parse(point.recordedAt) > lastServerAt,
            ),
        ];
    }, [locationTrail, liveTrail]);

    // Live updates for THIS asset only: location polls refresh position +
    // history, status transitions refresh the header badge. Bursts coalesce
    // into one partial reload with the union of affected props.
    const pendingKeys = useRef<Set<string>>(new Set());
    const timer = useRef<number | null>(null);
    const lastHistoryRefresh = useRef(0);

    useEffect(() => {
        // The page was just rendered from the server: no history refresh
        // for the first interval.
        lastHistoryRefresh.current = Date.now();

        // Coalesce into one partial reload with the union of affected props.
        const schedule = (...keys: string[]): void => {
            keys.forEach((key) => pendingKeys.current.add(key));

            if (timer.current !== null) {
                return;
            }

            timer.current = window.setTimeout(() => {
                const only = [...pendingKeys.current];
                pendingKeys.current.clear();
                timer.current = null;
                router.reload({ only });
            }, RELOAD_DEBOUNCE_MS);
        };

        const handler = (event: Event) => {
            const detail = (event as CustomEvent<TeamBroadcastDetail>).detail;

            switch (detail?.event) {
                case 'fleet.positions_updated': {
                    const { positions } =
                        detail.payload as unknown as FleetPositionsUpdatedPayload;
                    const mine = positions.find((p) => p.asset_id === asset.id);

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
                        schedule('asset', 'locationHistory', 'locationTrail');
                    }

                    return;
                }
                case 'fleet.telemetry_updated': {
                    const { assets } =
                        detail.payload as unknown as FleetTelemetryUpdatedPayload;

                    if (assets.some((entry) => entry.asset_id === asset.id)) {
                        schedule('telemetry');
                    }

                    return;
                }
                case 'asset.location_updated':
                case 'asset.status_changed':
                case 'asset.monitoring_changed': {
                    const payload = detail.payload as { asset_id?: number };

                    if (payload.asset_id !== asset.id) {
                        return;
                    }

                    // A one-off live lookup (critical event) still arrives
                    // per asset.
                    if (detail.event === 'asset.location_updated') {
                        schedule(
                            'asset',
                            'locationHistory',
                            'locationTrail',
                            'telemetry',
                        );
                    } else {
                        schedule('asset');
                    }

                    return;
                }
                default:
                    return;
            }
        };

        window.addEventListener(TEAM_BROADCAST_EVENT_NAME, handler);

        return () => {
            window.removeEventListener(TEAM_BROADCAST_EVENT_NAME, handler);

            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        };
    }, [asset.id, trailWindowHours]);

    return { asset, trail };
}
