import { useMemo, useState } from 'react';
import { isMoving } from '@/components/sam/map/markers';
import type { AssetMarker, AssetStatusValue } from '@/types/assets';
import { compareUnits, STATUS_ORDER } from './lib';

/**
 * Search and status filter of the live map, plus the fleet figures the header
 * and the status chips show.
 */
export function useRosterFilter(markers: AssetMarker[]) {
    const [query, setQuery] = useState('');
    const [hidden, setHidden] = useState<ReadonlySet<AssetStatusValue>>(
        () => new Set(),
    );

    const newestAt = useMemo(
        () =>
            markers.reduce<string | null>(
                (newest, m) =>
                    newest === null ||
                    Date.parse(m.recordedAt) > Date.parse(newest)
                        ? m.recordedAt
                        : newest,
                null,
            ),
        [markers],
    );

    const counts = useMemo(() => {
        const byStatus = new Map<AssetStatusValue, number>();
        markers.forEach((m) =>
            byStatus.set(m.status, (byStatus.get(m.status) ?? 0) + 1),
        );

        return byStatus;
    }, [markers]);

    const moving = useMemo(() => markers.filter(isMoving).length, [markers]);

    const visible = useMemo(() => {
        const q = query.trim().toLocaleLowerCase('es');

        return markers
            .filter((m) => !hidden.has(m.status))
            .filter(
                (m) =>
                    q === '' ||
                    m.name.toLocaleLowerCase('es').includes(q) ||
                    (m.code ?? '').toLocaleLowerCase('es').includes(q) ||
                    (m.driver ?? '').toLocaleLowerCase('es').includes(q),
            );
    }, [markers, hidden, query]);

    const listed = useMemo(() => [...visible].sort(compareUnits), [visible]);

    const toggleStatus = (status: AssetStatusValue) => {
        setHidden((current) => {
            const next = new Set(current);

            if (next.has(status)) {
                next.delete(status);
            } else {
                next.add(status);
            }

            return next;
        });
    };

    const clear = () => {
        setQuery('');
        setHidden(new Set());
    };

    return {
        query,
        setQuery,
        hidden,
        toggleStatus,
        clear,
        newestAt,
        counts,
        moving,
        visible,
        listed,
        presentStatuses: STATUS_ORDER.filter((s) => counts.has(s)),
        filtering: hidden.size > 0 || query.trim() !== '',
    };
}
