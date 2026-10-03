import { useEffect, useState } from 'react';
import type { AssetMarker } from '@/types/assets';

/**
 * The unit selected on the live map. Picking from the roster also asks the map
 * to fly to it (`focusRequest`); Escape clears the selection.
 */
export function useMapSelection(visible: AssetMarker[]) {
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [focusRequest, setFocusRequest] = useState(0);

    // A unit filtered out of view cannot stay selected. Adjusted during render
    // (not in an effect) so the stale selection never paints.
    if (selectedId !== null && !visible.some((m) => m.id === selectedId)) {
        setSelectedId(null);
    }

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setSelectedId(null);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const pickFromList = (id: number) => {
        setSelectedId(id);
        setFocusRequest((n) => n + 1);
    };

    return { selectedId, setSelectedId, focusRequest, pickFromList };
}
