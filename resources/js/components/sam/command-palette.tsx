import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

interface PaletteIncident {
    id: string;
    title: string;
    severity: string;
    status: string;
}

interface PaletteAction {
    id: string;
    label: string;
    description: string;
}

interface CommandPaletteProps {
    open: boolean;
    onClose: () => void;
    onPickIncident?: (id: string) => void;
}

const MOCK_INCIDENTS: PaletteIncident[] = [
    {
        id: 'INC-2026-04822',
        title: 'Colisión frontal detectada — T-412 · Volvo FH16',
        severity: 'critical',
        status: 'in-progress',
    },
    {
        id: 'INC-2026-04821',
        title: 'Frenado brusco > 0.6 g — T-118 · Scania R450',
        severity: 'high',
        status: 'new',
    },
    {
        id: 'INC-2026-04820',
        title: 'Exceso de velocidad 92/70 km/h — T-207 · MB Actros',
        severity: 'medium',
        status: 'assigned',
    },
    {
        id: 'INC-2026-04819',
        title: 'Sin cinturón detectado — T-301',
        severity: 'low',
        status: 'triaging',
    },
    {
        id: 'INC-2026-04818',
        title: 'Posible fatiga del conductor — T-502',
        severity: 'high',
        status: 'assigned',
    },
];

const MOCK_ACTIONS: PaletteAction[] = [
    {
        id: 'action-dashboard',
        label: 'Ir al panel',
        description: 'Vista general de operaciones',
    },
    {
        id: 'action-incidents',
        label: 'Ir a Incidentes',
        description: 'Bandeja de incidentes activos',
    },
    {
        id: 'action-map',
        label: 'Mapa en vivo',
        description: 'Posición de activos en tiempo real',
    },
];

const SEVERITY_CLASS: Record<string, string> = {
    critical: 'text-severity-critical',
    high: 'text-severity-high',
    medium: 'text-severity-medium',
    low: 'text-severity-low',
    info: 'text-severity-info',
};

export function CommandPalette({
    open,
    onClose,
    onPickIncident,
}: CommandPaletteProps) {
    const [query, setQuery] = useState('');
    const [activeIdx, setActiveIdx] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);

    // Focus the input when the palette mounts (which only happens when open=true).
    useEffect(() => {
        inputRef.current?.focus();
    }, []);

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

    const filteredIncidents = MOCK_INCIDENTS.filter(
        (i) =>
            i.title.toLowerCase().includes(query.toLowerCase()) ||
            i.id.toLowerCase().includes(query.toLowerCase()),
    );

    const filteredActions = MOCK_ACTIONS.filter(
        (a) =>
            a.label.toLowerCase().includes(query.toLowerCase()) ||
            a.description.toLowerCase().includes(query.toLowerCase()),
    );

    if (!open) {
        return null;
    }

    return (
        <div
            className="fixed inset-0 z-[600] grid place-items-start justify-center bg-black/50 pt-[12vh] backdrop-blur-sm"
            onClick={onClose}
            aria-modal="true"
            role="dialog"
            aria-label="Paleta de comandos"
        >
            <div
                className="w-full max-w-[620px] overflow-hidden rounded-[10px] border border-border bg-surface-1 shadow-xl"
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
                        className="flex-1 border-none bg-transparent text-[14px] font-medium text-fg-1 outline-none placeholder:text-fg-3"
                        placeholder="Buscar incidentes, activos, acciones…"
                    />
                    <kbd className="rounded-sm border border-b-2 border-border bg-surface-2 px-1.5 py-0.5 font-mono text-[10px] text-fg-2">
                        ESC
                    </kbd>
                </div>

                {/* Incidents group */}
                {filteredIncidents.length > 0 && (
                    <div>
                        <div className="px-3.5 py-2.5 text-[10px] font-semibold tracking-[0.08em] text-fg-3 uppercase">
                            Incidentes recientes
                        </div>
                        {filteredIncidents.map((incident, idx) => (
                            <div
                                key={incident.id}
                                className={cn(
                                    'flex cursor-pointer items-center gap-2.5 px-3.5 py-2 text-[13px] transition-colors duration-75',
                                    idx === activeIdx
                                        ? 'bg-primary/20'
                                        : 'hover:bg-surface-2',
                                )}
                                onClick={() => {
                                    onPickIncident?.(incident.id);
                                    onClose();
                                }}
                                onMouseEnter={() => setActiveIdx(idx)}
                            >
                                <span
                                    className={cn(
                                        'shrink-0 font-mono text-[11px] font-semibold',
                                        SEVERITY_CLASS[incident.severity] ??
                                            'text-fg-3',
                                    )}
                                >
                                    {incident.id.replace('INC-2026-', 'INC-')}
                                </span>
                                <span className="flex-1 truncate text-fg-1">
                                    {incident.title}
                                </span>
                                <span className="shrink-0 text-[11px] text-fg-3">
                                    {incident.status}
                                </span>
                            </div>
                        ))}
                    </div>
                )}

                {/* Actions group */}
                {filteredActions.length > 0 && (
                    <div>
                        <div className="border-t border-border px-3.5 py-2.5 text-[10px] font-semibold tracking-[0.08em] text-fg-3 uppercase">
                            Acciones
                        </div>
                        {filteredActions.map((action, idx) => {
                            const absoluteIdx = filteredIncidents.length + idx;

                            return (
                                <div
                                    key={action.id}
                                    className={cn(
                                        'flex cursor-pointer items-center gap-2.5 px-3.5 py-2 text-[13px] transition-colors duration-75',
                                        absoluteIdx === activeIdx
                                            ? 'bg-primary/20'
                                            : 'hover:bg-surface-2',
                                    )}
                                    onMouseEnter={() =>
                                        setActiveIdx(absoluteIdx)
                                    }
                                >
                                    <span className="flex-1 text-fg-1">
                                        {action.label}
                                    </span>
                                    <span className="shrink-0 text-[11px] text-fg-3">
                                        {action.description}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                )}

                {filteredIncidents.length === 0 &&
                    filteredActions.length === 0 && (
                        <div className="px-3.5 py-6 text-center text-[13px] text-fg-3">
                            Sin resultados para «{query}»
                        </div>
                    )}
            </div>
        </div>
    );
}
