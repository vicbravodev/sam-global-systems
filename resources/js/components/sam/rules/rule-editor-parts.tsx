import type { ReactNode } from 'react';

/** Aviso de solo lectura dentro de un editor. */
export function ReadOnlyNote({ children }: { children: ReactNode }) {
    return (
        <p className="rounded-md border border-border bg-surface-2 px-3 py-2 text-xs leading-relaxed text-fg-2">
            {children}
        </p>
    );
}
