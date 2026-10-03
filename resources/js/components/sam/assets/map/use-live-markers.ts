import { useEffect, useRef, useState } from 'react';
import { useReloadBuffer, useTeamBroadcast } from '@/hooks/use-team-broadcasts';
import type { AssetMarker, AssetStatusValue } from '@/types/assets';
import { applyPositions } from './lib';

// Reload (to pick up brand-new positioned assets) at most this often.
const RELOAD_DEBOUNCE_MS = 5000;

/**
 * The fleet markers on screen, kept live by the telematics feed. Live updates
 * move markers IN MEMORY (no server roundtrip); the server prop re-seeds them
 * whenever it refreshes.
 */
export function useLiveMarkers(serverMarkers: AssetMarker[]): AssetMarker[] {
    const [markers, setMarkers] = useState<AssetMarker[]>(serverMarkers);

    // Re-sync in-memory markers whenever the server prop refreshes.
    useEffect(() => {
        setMarkers(serverMarkers);
    }, [serverMarkers]);

    // A debounced partial reload (shared buffer: coalesced, paused while
    // hidden) only fires when an unknown unit shows up (it just got its first
    // position). The decision is taken here, against the markers on screen,
    // and the state updaters below stay pure (StrictMode runs them twice).
    const reload = useReloadBuffer({ debounceMs: RELOAD_DEBOUNCE_MS });
    const reloadRequestedFor = useRef<Set<number>>(new Set());

    useTeamBroadcast(
        [
            'fleet.positions_updated',
            'asset.location_updated',
            'asset.status_changed',
        ],
        (detail) => {
            if (detail.event === 'fleet.positions_updated') {
                // One batch per feed cycle (~5 s) for the whole fleet.
                const byId = new Map(
                    detail.payload.positions.map((p) => [p.asset_id, p]),
                );
                const onMap = new Set(markers.map((m) => m.id));

                // An asset reporting its first position is not on the map
                // yet: reload once for it, not on every feed tick (an asset
                // the map never lists would otherwise reload it forever).
                const firstSeen = [...byId.keys()].filter(
                    (id) =>
                        !onMap.has(id) && !reloadRequestedFor.current.has(id),
                );

                if (firstSeen.length > 0) {
                    firstSeen.forEach((id) =>
                        reloadRequestedFor.current.add(id),
                    );
                    reload.schedule(['assets', 'unpositionedCount']);
                }

                setMarkers((prev) => applyPositions(prev, byId));

                return;
            }

            if (detail.event === 'asset.location_updated') {
                const payload = detail.payload;

                if (!markers.some((m) => m.id === payload.asset_id)) {
                    reload.schedule(['assets', 'unpositionedCount']);

                    return;
                }

                setMarkers((prev) =>
                    prev.map((m) =>
                        m.id === payload.asset_id &&
                        Date.parse(payload.recorded_at) >=
                            Date.parse(m.recordedAt)
                            ? {
                                  ...m,
                                  latitude: payload.latitude,
                                  longitude: payload.longitude,
                                  recordedAt: payload.recorded_at,
                              }
                            : m,
                    ),
                );

                return;
            }

            const payload = detail.payload as {
                asset_id: number;
                new_status: string;
            };

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
        },
    );

    return markers;
}
