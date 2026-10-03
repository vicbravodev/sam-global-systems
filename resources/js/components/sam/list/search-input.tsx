import { Search, X } from 'lucide-react';
import { useEffect, useEffectEvent, useState } from 'react';

import { cn } from '@/lib/utils';

interface Props {
    /** Applied value from the server; the input re-syncs when it changes. */
    value: string | null;
    onApply: (value: string | null) => void;
    placeholder?: string;
    /** Debounce before firing a reload. */
    delay?: number;
    className?: string;
}

/**
 * Debounced free-text search shared by the list pages. Keeps its own draft
 * state, re-syncs when the applied filter changes externally (back/forward
 * navigation, "Limpiar") and offers a clear button while non-empty.
 */
export function SearchInput({
    value,
    onApply,
    placeholder = 'Buscar…',
    delay = 350,
    className,
}: Props) {
    const [draft, setDraft] = useState(value ?? '');
    const [applied, setApplied] = useState(value);

    // El filtro aplicado cambió desde fuera: el borrador lo sigue.
    if (applied !== value) {
        setApplied(value);
        setDraft(value ?? '');
    }

    const flush = useEffectEvent((text: string) => {
        const next = text.trim();

        if (next !== (value ?? '')) {
            onApply(next === '' ? null : next);
        }
    });

    useEffect(() => {
        const timer = setTimeout(() => flush(draft), delay);

        return () => clearTimeout(timer);
    }, [draft, delay]);

    return (
        <label
            className={cn(
                'flex items-center gap-1.5 rounded-md border border-border bg-surface-1 px-2.5 py-1.5 text-xs text-fg-3 transition-colors focus-within:border-primary/50',
                className,
            )}
        >
            <Search size={12} aria-hidden="true" />
            <input
                type="search"
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                placeholder={placeholder}
                aria-label={placeholder}
                className="w-48 border-none bg-transparent text-xs text-fg-1 outline-none placeholder:text-fg-3 [&::-webkit-search-cancel-button]:hidden"
            />
            {draft !== '' && (
                <button
                    type="button"
                    onClick={() => setDraft('')}
                    aria-label="Borrar búsqueda"
                    className="grid place-items-center text-fg-3 hover:text-fg-1"
                >
                    <X size={11} />
                </button>
            )}
        </label>
    );
}
