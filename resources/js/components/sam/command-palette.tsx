import { router, usePage } from '@inertiajs/react';
import { Search, Truck, User } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

interface PaletteIncident {
    id: number;
    /** Referencia visible por tenant (`INC-00036`). */
    reference: string;
    title: string;
    severity: string | null;
    status: string | null;
    /** Server-rendered status string (IncidentStatusPresenter). */
    statusLabel: string | null;
}

interface PaletteAsset {
    id: number;
    name: string;
    code: string | null;
    plate: string | null;
}

interface PaletteDriver {
    id: number;
    name: string;
    employeeCode: string | null;
}

interface PaletteResults {
    incidents?: PaletteIncident[];
    assets?: PaletteAsset[];
    drivers?: PaletteDriver[];
}

interface PaletteAction {
    id: string;
    label: string;
    description: string;
    href: (slug: string) => string;
    /** Sección requerida (props.nav); sin ella la acción no se ofrece. */
    can?: 'incidents';
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
        can: 'incidents',
    },
    {
        id: 'action-fleet',
        label: 'Ir a Flota',
        description: 'Unidades y su última señal',
        href: (slug) => `/${slug}/assets`,
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

const GROUP_TITLE =
    'border-t border-border px-3.5 py-2.5 text-3xs font-semibold tracking-caps text-fg-3 uppercase';

export function CommandPalette({ open, onClose }: CommandPaletteProps) {
    const page = usePage();
    const slug =
        (
            page.props as unknown as {
                currentTeam?: { slug?: string | null } | null;
            }
        ).currentTeam?.slug ?? null;
    const nav = page.props.nav;

    const [query, setQuery] = useState('');
    const [incidents, setIncidents] = useState<PaletteIncident[]>([]);
    const [assets, setAssets] = useState<PaletteAsset[]>([]);
    const [drivers, setDrivers] = useState<PaletteDriver[]>([]);
    const [activeIdx, setActiveIdx] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);

    // El componente vive montado en el layout: enfocar en cada apertura, no
    // sólo al montar. El frame de espera deja que el overlay exista en el DOM
    // antes de pedir el foco.
    useEffect(() => {
        if (!open) {
            return;
        }

        const frame = window.requestAnimationFrame(() =>
            inputRef.current?.focus(),
        );

        return () => window.cancelAnimationFrame(frame);
    }, [open]);

    // Real data: incidents, fleet units and drivers of the tenant, debounced
    // while typing. Each group only comes back if the role may open it.
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
                .then((data: PaletteResults | null) => {
                    if (data) {
                        setIncidents(data.incidents ?? []);
                        setAssets(data.assets ?? []);
                        setDrivers(data.drivers ?? []);
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
            (a.can === undefined || nav?.[a.can] === true) &&
            (a.label.toLowerCase().includes(query.toLowerCase()) ||
                a.description.toLowerCase().includes(query.toLowerCase())),
    );

    // Orden de navegación con flechas: incidentes → unidades → conductores
    // → acciones. Los índices absolutos de cada grupo parten de estos offsets.
    const assetOffset = incidents.length;
    const driverOffset = assetOffset + assets.length;
    const actionOffset = driverOffset + drivers.length;
    const totalItems = actionOffset + filteredActions.length;

    const go = (href: string) => {
        onClose();
        router.visit(href);
    };

    const pick = (index: number) => {
        if (slug === null) {
            return;
        }

        if (index < assetOffset) {
            const incident = incidents[index];

            if (incident) {
                go(`/${slug}/incidents/${incident.id}`);
            }

            return;
        }

        if (index < driverOffset) {
            const asset = assets[index - assetOffset];

            if (asset) {
                go(`/${slug}/assets/${asset.id}`);
            }

            return;
        }

        if (index < actionOffset) {
            const driver = drivers[index - driverOffset];

            if (driver) {
                go(`/${slug}/drivers/${driver.id}`);
            }

            return;
        }

        const action = filteredActions[index - actionOffset];

        if (action) {
            go(action.href(slug));
        }
    };

    const rowClass = (index: number) =>
        cn(
            'flex cursor-pointer items-center gap-2.5 px-3.5 py-2 text-sm transition-colors duration-75',
            index === activeIdx ? 'bg-primary/20' : 'hover:bg-surface-2',
        );

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
                        autoFocus
                        onChange={(e) => {
                            setQuery(e.target.value);
                            setActiveIdx(0);
                        }}
                        onKeyDown={handleInputKey}
                        role="combobox"
                        aria-expanded="true"
                        aria-controls="command-palette-results"
                        aria-label="Buscar incidentes, unidades, placas o conductores"
                        className="flex-1 border-none bg-transparent text-base font-medium text-fg-1 outline-none placeholder:text-fg-3"
                        placeholder="Buscar incidentes, unidades, placas, conductores…"
                    />
                    <kbd className="rounded-sm border border-b-2 border-border bg-surface-2 px-1.5 py-0.5 font-mono text-3xs text-fg-2">
                        ESC
                    </kbd>
                </div>

                <div
                    id="command-palette-results"
                    className="max-h-[60vh] overflow-y-auto"
                >
                    {/* Incidents group */}
                    {incidents.length > 0 && (
                        <div>
                            <div className="px-3.5 py-2.5 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                                Incidentes recientes
                            </div>
                            {incidents.map((incident, idx) => (
                                <div
                                    key={incident.id}
                                    className={rowClass(idx)}
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
                                        {incident.reference}
                                    </span>
                                    <span className="flex-1 truncate text-fg-1">
                                        {incident.title}
                                    </span>
                                    <span className="shrink-0 text-2xs text-fg-3">
                                        {incident.statusLabel ??
                                            incident.status ??
                                            ''}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}

                    {/* Fleet units group */}
                    {assets.length > 0 && (
                        <div>
                            <div className={GROUP_TITLE}>Unidades</div>
                            {assets.map((asset, idx) => {
                                const absoluteIdx = assetOffset + idx;

                                return (
                                    <div
                                        key={asset.id}
                                        className={rowClass(absoluteIdx)}
                                        onClick={() => pick(absoluteIdx)}
                                        onMouseEnter={() =>
                                            setActiveIdx(absoluteIdx)
                                        }
                                    >
                                        <Truck className="size-3.5 shrink-0 text-fg-3" />
                                        <span className="flex-1 truncate text-fg-1">
                                            {asset.name}
                                        </span>
                                        <span className="shrink-0 font-mono text-2xs text-fg-3">
                                            {[asset.code, asset.plate]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {/* Drivers group */}
                    {drivers.length > 0 && (
                        <div>
                            <div className={GROUP_TITLE}>Conductores</div>
                            {drivers.map((driver, idx) => {
                                const absoluteIdx = driverOffset + idx;

                                return (
                                    <div
                                        key={driver.id}
                                        className={rowClass(absoluteIdx)}
                                        onClick={() => pick(absoluteIdx)}
                                        onMouseEnter={() =>
                                            setActiveIdx(absoluteIdx)
                                        }
                                    >
                                        <User className="size-3.5 shrink-0 text-fg-3" />
                                        <span className="flex-1 truncate text-fg-1">
                                            {driver.name}
                                        </span>
                                        {driver.employeeCode && (
                                            <span className="shrink-0 font-mono text-2xs text-fg-3">
                                                {driver.employeeCode}
                                            </span>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {/* Actions group */}
                    {filteredActions.length > 0 && (
                        <div>
                            <div className={GROUP_TITLE}>Acciones</div>
                            {filteredActions.map((action, idx) => {
                                const absoluteIdx = actionOffset + idx;

                                return (
                                    <div
                                        key={action.id}
                                        className={rowClass(absoluteIdx)}
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
