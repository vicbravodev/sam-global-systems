import { isMoving, STATUS_URGENCY } from '@/components/sam/map/markers';
import type { RealtimeState } from '@/components/sam/realtime-status';
import type { useRealtimeConnection } from '@/hooks/use-realtime-connection';
import type { AssetMarker, AssetStatusValue } from '@/types/assets';
import type { FleetPosition } from '@/types/realtime';

export const STATUS_ORDER: AssetStatusValue[] = [
    'critical',
    'alert',
    'maintenance',
    'active',
    'inactive',
    'offline',
];

export function connectionToStatus(
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

/**
 * Lays a feed batch over the markers: a marker moves only to a position at
 * least as new as its own. Returns `prev` itself when nothing moved, so the
 * map and the roster skip the re-render.
 */
export function applyPositions(
    prev: AssetMarker[],
    byId: ReadonlyMap<number, FleetPosition>,
): AssetMarker[] {
    let changed = false;

    const next = prev.map((m) => {
        const p = byId.get(m.id);

        if (
            p === undefined ||
            Date.parse(p.recorded_at) < Date.parse(m.recordedAt)
        ) {
            return m;
        }

        changed = true;

        return {
            ...m,
            latitude: p.latitude,
            longitude: p.longitude,
            speed: p.speed_kph,
            heading: p.heading,
            moving: p.moving,
            recordedAt: p.recorded_at,
        };
    });

    return changed ? next : prev;
}

/** Most urgent first, then moving before parked, then by code. */
export function compareUnits(a: AssetMarker, b: AssetMarker): number {
    return (
        STATUS_URGENCY[b.status] - STATUS_URGENCY[a.status] ||
        Number(isMoving(b)) - Number(isMoving(a)) ||
        (a.code ?? a.name).localeCompare(b.code ?? b.name, 'es', {
            numeric: true,
        })
    );
}
