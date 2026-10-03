import type { AssetRow } from '@/types/assets';
import type { FleetPosition } from '@/types/realtime';

/**
 * A row with the newest live position laid over it, when that position is
 * newer than what the server rendered.
 */
export function withLivePosition(
    asset: AssetRow,
    live: FleetPosition | undefined,
): AssetRow {
    if (
        live === undefined ||
        (asset.lastLocation !== null &&
            Date.parse(live.recorded_at) <=
                Date.parse(asset.lastLocation.recordedAt))
    ) {
        return asset;
    }

    const signal =
        asset.lastSignalAt === null ||
        Date.parse(live.recorded_at) > Date.parse(asset.lastSignalAt)
            ? live.recorded_at
            : asset.lastSignalAt;

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
        lastSignalAt: signal,
    };
}

/**
 * Live positions for the rows on the current page: entries for units no
 * longer on the page are dropped, a position is replaced only by a newer
 * one, and `prev` itself is returned when nothing changed so React skips
 * the re-render.
 */
export function mergeLivePositions(
    prev: Map<number, FleetPosition>,
    visible: readonly FleetPosition[],
    onPage: ReadonlySet<number>,
): Map<number, FleetPosition> {
    let changed = false;
    const next = new Map<number, FleetPosition>();

    prev.forEach((position, id) => {
        if (onPage.has(id)) {
            next.set(id, position);
        } else {
            changed = true;
        }
    });

    visible.forEach((position) => {
        const current = next.get(position.asset_id);

        if (
            current !== undefined &&
            Date.parse(position.recorded_at) <= Date.parse(current.recorded_at)
        ) {
            return;
        }

        next.set(position.asset_id, position);
        changed = true;
    });

    return changed ? next : prev;
}
