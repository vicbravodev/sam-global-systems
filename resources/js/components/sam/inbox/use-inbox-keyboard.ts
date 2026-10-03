import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import incidentRoutes from '@/routes/incidents';
import type { MockIncident } from '@/types/sam';

interface UseInboxKeyboardOptions {
    rows: MockIncident[];
    selectedId: string | null;
    setSelectedId: (id: string | null) => void;
    onToggle: (id: string) => void;
    canAssign: boolean;
    onAssign: (incident: MockIncident) => void;
    teamSlug: string | null;
}

/**
 * Atajos de teclado que el footer anuncia (F2.2): J/K navegar, Enter abrir,
 * X seleccionar, A asignarme, Esc cerrar el panel. Nunca dentro de inputs ni
 * diálogos.
 */
export function useInboxKeyboard({
    rows,
    selectedId,
    setSelectedId,
    onToggle,
    canAssign,
    onAssign,
    teamSlug,
}: UseInboxKeyboardOptions) {
    useEffect(() => {
        const handler = (e: KeyboardEvent) => {
            if (e.metaKey || e.ctrlKey || e.altKey) {
                return;
            }

            const target = e.target as HTMLElement | null;

            if (
                target?.closest(
                    'input, textarea, select, [contenteditable="true"], [role="dialog"]',
                )
            ) {
                return;
            }

            if (e.key === 'Escape') {
                setSelectedId(null);

                return;
            }

            if (rows.length === 0) {
                return;
            }

            const key = e.key.toLowerCase();
            const idx = rows.findIndex((r) => r.id === selectedId);

            if (key === 'j' || key === 'k') {
                e.preventDefault();
                const next =
                    key === 'j'
                        ? rows[Math.min(idx + 1, rows.length - 1)]
                        : rows[Math.max(idx - 1, 0)];

                if (next) {
                    setSelectedId(next.id);
                }
            } else if (key === 'x' && selectedId !== null) {
                e.preventDefault();
                onToggle(selectedId);
            } else if (key === 'a' && selectedId !== null && canAssign) {
                e.preventDefault();
                const row = rows.find((r) => r.id === selectedId);

                if (row) {
                    onAssign(row);
                }
            } else if (e.key === 'Enter' && selectedId !== null) {
                // La fila enfocada ya maneja Enter (abre el panel).
                if (target?.closest('tr, button, a')) {
                    return;
                }

                const row = rows.find((r) => r.id === selectedId);

                if (row && teamSlug !== null) {
                    router.visit(
                        incidentRoutes.show([teamSlug, row.incidentId]),
                    );
                }
            }
        };

        window.addEventListener('keydown', handler);

        return () => window.removeEventListener('keydown', handler);
    });
}
