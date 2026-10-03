import { useState } from 'react';
import type { MockIncident } from '@/types/sam';

/** Checkbox selection of inbox rows (the bulk-action target). */
export function useInboxSelection(rows: MockIncident[]) {
    const [selected, setSelected] = useState<Set<string>>(new Set());

    const toggle = (id: string) => {
        setSelected((prev) => {
            const next = new Set(prev);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    const toggleAll = () => {
        if (selected.size === rows.length) {
            setSelected(new Set());
        } else {
            setSelected(new Set(rows.map((r) => r.id)));
        }
    };

    const clear = () => setSelected(new Set());

    return {
        selected,
        toggle,
        toggleAll,
        clear,
        allChecked: rows.length > 0 && selected.size === rows.length,
    };
}
