import { router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

interface PaletteIncident {
    id: number;
    title: string;
    severity: string | null;
    status: string | null;
}

interface PaletteAction {
    id: string;
    label: string;
    description: string;
    href: (slug: string) => string;
}

interface CommandPaletteProps {
    open: boolean;
    onClose: () => void;
}

const ACTIONS: PaletteAction[] = [
    {
        id: 'action-dashboard',
        label: 'Ir al panel',
        description: 'Vista general de operaciones',
        href: (slug) => `/${slug}/dashboard`,
    },
    {
        id: 'action-incidents',
        label: 'Ir a Incidentes',
        description: 'Bandeja de incidentes activos',
        href: (slug) => `/${slug}/incidents`,
    },
    {
        id: 'action-map',
        label: 'Mapa en vivo',
        description: 'Posición de activos en tiempo real',
        href: (slug) => `/${slug}/assets/map`,
    },
];

const SEVERITY_CLASS: Record<string, string> = {
    critical: 'text-severity-critical',
    high: 'text-severity-high',
    medium: 'text-severity-medium',
    low: 'text-severity-low',
    info: 'text-severity-info',
};

export function CommandPalette({ open, onClose }: CommandPaletteProps) {
    const page = usePage();
    const slug =
        (
            page.props as unknown as {
                currentTeam?: { slug?: string | null } | null;
            }
        ).currentTeam?.slug ?? null;

    const [query, setQuery] = useState('');
    const [incidents, setIncidents] = useState<PaletteIncident[]>([]);
    const [activeIdx, setActiveIdx] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);

    // Focus the input when the palette mounts (which only happens when open=true).
    useEffect(() => {
        inputRef.current?.focus();
    }, []);

    // Real data: most recent tenant incidents, debounced while typing.
    useEffect(() => {
        if (!open || slug === null) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`/${slug}/palette-search?q=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((data: { incidents?: PaletteIncident[] } | null) => {
                    if (data?.incidents) {
                        setIncidents(data.incidents);
                        setActiveIdx(0);
                    }
                })
                .catch(() => {
                    // Red caída o abort: la paleta sigue mostrando lo último.
                });
        }, 200);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [open, slug, query]);

    const filteredActions = ACTIONS.filter(
        (a) =>
            a.label.toLowerCase().includes(query.toLowerCase()) ||
            a.description.toLowerCase().includes(query.toLowerCase()),
    );

    const totalItems = incidents.length + filteredActions.length;

    const pick = (index: number) => {
        if (slug === null) {
            return;
        }

        if (index < incidents.length) {
            const incident = incidents[index];

            if (incident) {
                onClose();
                router.visit(`/${slug}/incidents/${incident.id}`);
            }

            return;
        }

        const action = filteredActions[index - incidents.length];

        if (action) {
            onClose();
            router.visit(action.href(slug));
        }
    };

    useEffect(() => {
        const handleKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                onClose();
            }
        };

        if (open) {
            document.addEventListener('keydown', handleKey);
        }

        return () => document.removeEventListener('keydown', handleKey);
    }, [open, onClose]);

    if (!open) {
        return null;
    }

    const handleInputKey = (e: React.KeyboardEvent) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActiveIdx((prev) => Math.min(prev + 1, totalItems - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActiveIdx((prev) => Math.max(prev - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            pick(activeIdx);
        }
    };

    return (
        <div
            className="fixed inset-0 z-[600] grid place-items-start justify-center bg-black/50 pt-[12vh] backdrop-blur-sm"
            onClick={onClose}
            aria-modal="true"
            role="dialog"
            aria-label="Paleta de comandos"
        >
            <div
                className="w-full max-w-[620px] overflow-hidden rounded-lg border border-border bg-surface-1 shadow-xl"
                onClick={(e) => e.stopPropagation()}
            >
                {/* Search row */}
                <div className="flex items-center gap-2.5 border-b border-border px-3.5 py-3">
                    <Search className="size-3.5 shrink-0 text-fg-3" />
                    <input
                        ref={inputRef}
                        type="text"
                        value={query}
                        onChange={(e) => {
                            setQuery(e.target.value);
                            setActiveIdx(0);
                        }}
                        onKeyDown={handleInputKey}
                        role="combobox"
                        aria-expanded="true"
                        aria-controls="command-palette-results"
                        className="flex-1 border-none bg-transparent text-base font-medium text-fg-1 outline-none placeholder:text-fg-3"
                        placeholder="Buscar incidentes, acciones…"
                    />
                    <kbd className="rounded-sm border border-b-2 border-border bg-surface-2 px-1.5 py-0.5 font-mono text-3xs text-fg-2">
                        ESC
                    </kbd>
                </div>

                <div id="command-palette-results">
                    {/* Incidents group */}
                    {incidents.length > 0 && (
                        <div>
                            <div className="px-3.5 py-2.5 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                                Incidentes recientes
                            </div>
                            {incidents.map((incident, idx) => (
                                <div
                                    key={incident.id}
                                    className={cn(
                                        'flex cursor-pointer items-center gap-2.5 px-3.5 py-2 text-sm transition-colors duration-75',
                                        idx === activeIdx
                                            ? 'bg-primary/20'
                                            : 'hover:bg-surface-2',
                                    )}
                                    onClick={() => pick(idx)}
                                    onMouseEnter={() => setActiveIdx(idx)}
                                >
                                    <span
                                        className={cn(
                                            'shrink-0 font-mono text-2xs font-semibold',
                                            SEVERITY_CLASS[
                                                incident.severity ?? ''
                                            ] ?? 'text-fg-3',
                                        )}
                                    >
                                        INC-{incident.id}
                                    </span>
                                    <span className="flex-1 truncate text-fg-1">
                                        {incident.title}
                                    </span>
                                    <span className="shrink-0 text-2xs text-fg-3">
                                        {incident.status ?? ''}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}

                    {/* Actions group */}
                    {filteredActions.length > 0 && (
                        <div>
                            <div className="border-t border-border px-3.5 py-2.5 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                                Acciones
                            </div>
                            {filteredActions.map((action, idx) => {
                                const absoluteIdx = incidents.length + idx;

                                return (
                                    <div
                                        key={action.id}
                                        className={cn(
                                            'flex cursor-pointer items-center gap-2.5 px-3.5 py-2 text-sm transition-colors duration-75',
                                            absoluteIdx === activeIdx
                                                ? 'bg-primary/20'
                                                : 'hover:bg-surface-2',
                                        )}
                                        onClick={() => pick(absoluteIdx)}
                                        onMouseEnter={() =>
                                            setActiveIdx(absoluteIdx)
                                        }
                                    >
                                        <span className="flex-1 text-fg-1">
                                            {action.label}
                                        </span>
                                        <span className="shrink-0 text-2xs text-fg-3">
                                            {action.description}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {totalItems === 0 && (
                        <div className="px-3.5 py-6 text-center text-sm text-fg-3">
                            Sin resultados para «{query}»
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
