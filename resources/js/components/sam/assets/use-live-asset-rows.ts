import { useRef, useState } from 'react';
import {
    useBroadcastReload,
    useTeamBroadcast,
} from '@/hooks/use-team-broadcasts';
import type { AssetRow } from '@/types/assets';
import type { FleetPosition } from '@/types/realtime';
import { mergeLivePositions, withLivePosition } from './live-positions';

// Props each status / monitoring broadcast refreshes (debounced below). Feed
// positions (`fleet.positions_updated`) are applied to the rows in memory
// instead, and a location event only reloads the rows: the fleet pulse
// (`summary`, several EXISTS over the snapshot tables) and the monitoring
// counts only move on status / monitoring changes.
const RELOAD_KEYS_BY_EVENT = {
    'asset.status_changed': ['assets', 'pagination', 'summary', 'monitoring'],
    'asset.monitoring_changed': [
        'assets',
        'pagination',
        'summary',
        'monitoring',
    ],
} as const;

// Location events arrive in bursts (one per asset): coalesce them over a
// wider window than the rarer status / monitoring transitions.
const RELOAD_DEBOUNCE_MS = 2000;
const LOCATION_RELOAD_DEBOUNCE_MS = 10000;

// The pulse strip (moving / reporting counts) follows live positions, but a
// server roundtrip every feed cycle would be waste: at most this often.
const SUMMARY_REFRESH_MS = 30_000;

/**
 * The fleet list rows with the newest live positions laid over them.
 *
 * Live updates: status / monitoring transitions refresh the list and the
 * pulse strip through the shared reload buffer (coalesced, paused while the
 * tab is hidden, full resync after a socket drop). Location events feed the
 * same buffer over a wider window; feed positions move the rows in memory and
 * refresh the pulse at most every SUMMARY_REFRESH_MS.
 */
export function useLiveAssetRows(serverAssets: AssetRow[] | undefined) {
    const [livePositions, setLivePositions] = useState<
        Map<number, FleetPosition>
    >(() => new Map());

    const reload = useBroadcastReload(RELOAD_KEYS_BY_EVENT, {
        debounceMs: RELOAD_DEBOUNCE_MS,
    });
    const lastSummaryRefresh = useRef(0);

    useTeamBroadcast(
        ['fleet.positions_updated', 'asset.location_updated'],
        (detail) => {
            if (detail.event === 'asset.location_updated') {
                reload.schedule(['assets'], LOCATION_RELOAD_DEBOUNCE_MS);

                return;
            }

            // Only the rows on this page use live positions: a fleet-wide
            // batch for units on other pages must neither grow the map nor
            // re-render the table.
            const onPage = new Set((serverAssets ?? []).map((a) => a.id));
            const visible = detail.payload.positions.filter((p) =>
                onPage.has(p.asset_id),
            );

            setLivePositions((prev) =>
                mergeLivePositions(prev, visible, onPage),
            );

            if (Date.now() - lastSummaryRefresh.current > SUMMARY_REFRESH_MS) {
                lastSummaryRefresh.current = Date.now();
                reload.schedule(['summary', 'monitoring']);
            }
        },
    );

    return (serverAssets ?? []).map((asset) =>
        withLivePosition(asset, livePositions.get(asset.id)),
    );
}
