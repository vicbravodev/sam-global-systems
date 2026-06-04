import { Head, router, usePage } from '@inertiajs/react';
import {
    Filter,
    Inbox,
    LayoutList,
    Loader2,
    RefreshCw,
    Rows3,
    Search,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { InboxGrouped } from '@/components/sam/inbox/inbox-grouped';
import { InboxStream } from '@/components/sam/inbox/inbox-stream';
import { InboxTable } from '@/components/sam/inbox/inbox-table';
import { IncidentDetailPanel } from '@/components/sam/incident-detail';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useInitials } from '@/hooks/use-initials';
import { TEAM_BROADCAST_EVENT_NAME } from '@/hooks/use-team-broadcasts';
import type { TeamBroadcastDetail } from '@/hooks/use-team-broadcasts';
import { postJson, readErrorMessage } from '@/lib/sam-fetch';
import { cn } from '@/lib/utils';
import type {
    InboxDensity,
    InboxFilterOptions,
    InboxFilters,
    InboxLayout,
    InboxTab,
    IncidentDetail,
    MockIncident,
} from '@/types/sam';

// ---- BulkBar ----

interface BulkBarProps {
    count: number;
    pending: string | null;
    onAssign: () => void;
    onEscalate: () => void;
    onDiscard: () => void;
    onClear: () => void;
}

function BulkBar({
    count,
    pending,
    onAssign,
    onEscalate,
    onDiscard,
    onClear,
}: BulkBarProps) {
    const busy = pending !== null;

    return (
        <div className="flex shrink-0 items-center gap-2.5 border-b border-border bg-primary/18 px-5 py-2">
            <span className="text-[12px] font-semibold text-primary">
                {count} seleccionados
            </span>
            <Button
                size="sm"
                variant="outline"
                onClick={onAssign}
                disabled={busy}
            >
                {pending === 'assign' ? (
                    <Loader2 size={12} className="animate-spin" />
                ) : null}
                Asignarme
            </Button>
            <Button
                size="sm"
                variant="outline"
                onClick={onEscalate}
                disabled={busy}
            >
                {pending === 'escalate' ? (
                    <Loader2 size={12} className="animate-spin" />
                ) : null}
                Escalar
            </Button>
            <Button
                size="sm"
                variant="outline"
                onClick={onDiscard}
                disabled={busy}
            >
                {pending === 'discard' ? (
                    <Loader2 size={12} className="animate-spin" />
                ) : null}
                Descartar
            </Button>
            <Button
                size="sm"
                variant="ghost"
                onClick={onClear}
                className="ml-auto"
                disabled={busy}
            >
                Deseleccionar
            </Button>
        </div>
    );
}

// ---- PageHead ----

interface PageHeadProps {
    openCount: number;
    criticalCount: number;
    layout: InboxLayout;
    setLayout: (l: InboxLayout) => void;
    onRefresh: () => void;
    refreshing: boolean;
    onAssignOldestCritical: () => void;
    assigningOldest: boolean;
}

function PageHead({
    openCount,
    criticalCount,
    layout,
    setLayout,
    onRefresh,
    refreshing,
    onAssignOldestCritical,
    assigningOldest,
}: PageHeadProps) {
    const layouts: {
        value: InboxLayout;
        icon: React.ReactNode;
        label: string;
    }[] = [
        {
            value: 'table',
            icon: <LayoutList size={14} strokeWidth={1.75} />,
            label: 'Tabla',
        },
        {
            value: 'grouped',
            icon: <Rows3 size={14} strokeWidth={1.75} />,
            label: 'Agrupado',
        },
        {
            value: 'stream',
            icon: <Inbox size={14} strokeWidth={1.75} />,
            label: 'Stream',
        },
    ];

    return (
        <header className="flex shrink-0 items-center justify-between gap-3 border-b border-border bg-surface-1 px-5 py-3">
            <div className="flex items-center gap-3">
                <h1 className="text-[15px] font-semibold text-fg-1">
                    Bandeja de incidentes
                </h1>
                <div className="flex items-center gap-2 text-[12px] text-fg-3">
                    <span>
                        <span className="font-medium text-fg-1">
                            {openCount}
                        </span>{' '}
                        abiertos
                    </span>
                    <span>·</span>
                    <span className="flex items-center gap-1">
                        <span className="relative inline-flex size-1.5">
                            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-severity-critical opacity-60" />
                            <span className="relative inline-flex size-1.5 rounded-full bg-severity-critical" />
                        </span>
                        <span className="font-medium text-severity-critical">
                            {criticalCount}
                        </span>{' '}
                        críticos
                    </span>
                </div>
            </div>

            <div className="flex items-center gap-2">
                {/* Layout switcher */}
                <div className="flex items-center gap-0.5 rounded-md border border-border bg-surface-2 p-0.5">
                    {layouts.map((l) => (
                        <button
                            key={l.value}
                            type="button"
                            onClick={() => setLayout(l.value)}
                            className={cn(
                                'inline-flex items-center gap-1 rounded-sm px-2 py-1 text-[11px] font-medium transition-colors',
                                layout === l.value
                                    ? 'bg-surface-1 text-fg-1 shadow-sm'
                                    : 'text-fg-3 hover:text-fg-2',
                            )}
                            title={l.label}
                        >
                            {l.icon}
                        </button>
                    ))}
                </div>

                <Button
                    variant="ghost"
                    size="sm"
                    onClick={onRefresh}
                    disabled={refreshing}
                >
                    <RefreshCw
                        size={13}
                        className={cn(refreshing && 'animate-spin')}
                    />
                    Refrescar
                </Button>

                <Button
                    variant="outline"
                    size="sm"
                    onClick={onAssignOldestCritical}
                    disabled={assigningOldest}
                >
                    {assigningOldest ? (
                        <Loader2 size={13} className="animate-spin" />
                    ) : null}
                    Asignarme crítico más viejo
                </Button>
            </div>
        </header>
    );
}

// ---- TabBar ----

interface TabBarProps {
    tab: InboxTab;
    setTab: (t: InboxTab) => void;
    density: InboxDensity;
    setDensity: (d: InboxDensity) => void;
    openIncidents: MockIncident[];
}

const TABS: { value: InboxTab; label: string }[] = [
    { value: 'open', label: 'Abiertos' },
    { value: 'mine', label: 'Míos' },
    { value: 'unassigned', label: 'Sin asignar' },
    { value: 'sla', label: 'SLA crítico' },
    { value: 'all', label: 'Todos' },
    { value: 'discarded', label: 'Descartados' },
];

const DENSITY_OPTS: { value: InboxDensity; label: string }[] = [
    { value: 'compact', label: 'C' },
    { value: 'comfortable', label: 'M' },
    { value: 'relaxed', label: 'R' },
];

function TabBar({
    tab,
    setTab,
    density,
    setDensity,
    openIncidents,
}: TabBarProps) {
    return (
        <div className="flex shrink-0 items-center justify-between border-b border-border bg-surface-1 px-5">
            <nav className="flex items-center gap-0">
                {TABS.map((t) => (
                    <button
                        key={t.value}
                        type="button"
                        onClick={() => setTab(t.value)}
                        className={cn(
                            '-mb-px border-b-2 px-3.5 py-2.5 text-[12px] font-medium transition-colors',
                            tab === t.value
                                ? 'border-primary text-fg-1'
                                : 'border-transparent text-fg-3 hover:text-fg-2',
                        )}
                    >
                        {t.label}
                        {t.value === 'open' && openIncidents.length > 0 && (
                            <span className="ml-1.5 font-mono text-[10px] text-fg-3">
                                {openIncidents.length}
                            </span>
                        )}
                    </button>
                ))}
            </nav>

            {/* Density */}
            <div className="flex items-center gap-1 py-1.5">
                {DENSITY_OPTS.map((d) => (
                    <button
                        key={d.value}
                        type="button"
                        onClick={() => setDensity(d.value)}
                        className={cn(
                            'h-6 w-6 rounded-sm text-[10px] font-semibold transition-colors',
                            density === d.value
                                ? 'bg-surface-3 text-fg-1'
                                : 'text-fg-3 hover:text-fg-2',
                        )}
                        title={d.value}
                    >
                        {d.label}
                    </button>
                ))}
            </div>
        </div>
    );
}

// ---- FilterBar ----

interface FilterDropdownProps {
    label: string;
    value: string | null;
    options: { value: string; label: string }[];
    onChange: (value: string | null) => void;
}

function FilterDropdown({
    label,
    value,
    options,
    onChange,
}: FilterDropdownProps) {
    const active = value !== null;
    const activeLabel = options.find((o) => o.value === value)?.label;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'flex items-center gap-1 rounded-sm border px-2.5 py-1.5 text-[11px] transition-colors',
                        active
                            ? 'border-primary/40 bg-primary/10 text-primary'
                            : 'border-border bg-surface-1 text-fg-2 hover:border-border-strong',
                    )}
                >
                    <Filter size={11} />
                    {active && activeLabel ? `${label}: ${activeLabel}` : label}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="max-h-72 overflow-y-auto"
            >
                <DropdownMenuRadioGroup
                    value={value ?? ''}
                    onValueChange={(v) => onChange(v === '' ? null : v)}
                >
                    <DropdownMenuRadioItem value="">
                        Todos
                    </DropdownMenuRadioItem>
                    {options.length > 0 && <DropdownMenuSeparator />}
                    {options.map((o) => (
                        <DropdownMenuRadioItem key={o.value} value={o.value}>
                            {o.label}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

interface FilterBarProps {
    filters: InboxFilters;
    options: InboxFilterOptions;
    onApply: (next: InboxFilters) => void;
}

function FilterBar({ filters, options, onApply }: FilterBarProps) {
    const [search, setSearch] = useState(filters.q ?? '');

    // Keep the input in sync when filters are reset/changed externally.
    useEffect(() => {
        setSearch(filters.q ?? '');
    }, [filters.q]);

    // Debounce the free-text search before firing a reload.
    useEffect(() => {
        const current = filters.q ?? '';
        const next = search.trim();

        if (next === current) {
            return;
        }

        const timer = setTimeout(() => {
            onApply({ ...filters, q: next === '' ? null : next });
        }, 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const providerOptions = options.providers.map((p) => ({
        value: p,
        label: p,
    }));

    const hasActive =
        filters.q !== null ||
        filters.severity !== null ||
        filters.status !== null ||
        filters.provider !== null ||
        filters.shift !== null;

    return (
        <div className="flex shrink-0 items-center gap-2 border-b border-border bg-background px-5 py-2">
            <div className="mr-1 flex items-center gap-1.5 rounded-md border border-border bg-surface-1 px-2.5 py-1.5 text-[12px] text-fg-3">
                <Search size={12} />
                <input
                    type="text"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Buscar incidente…"
                    className="w-40 border-none bg-transparent text-[12px] text-fg-1 outline-none placeholder:text-fg-3"
                />
            </div>

            <FilterDropdown
                label="Severidad"
                value={filters.severity}
                options={options.severities}
                onChange={(v) => onApply({ ...filters, severity: v })}
            />
            <FilterDropdown
                label="Estado"
                value={filters.status}
                options={options.statuses}
                onChange={(v) => onApply({ ...filters, status: v })}
            />
            <FilterDropdown
                label="Proveedor"
                value={filters.provider}
                options={providerOptions}
                onChange={(v) => onApply({ ...filters, provider: v })}
            />
            <FilterDropdown
                label="Turno"
                value={filters.shift}
                options={options.shifts}
                onChange={(v) => onApply({ ...filters, shift: v })}
            />

            {hasActive && (
                <button
                    type="button"
                    onClick={() =>
                        onApply({
                            q: null,
                            severity: null,
                            status: null,
                            provider: null,
                            shift: null,
                        })
                    }
                    className="flex items-center gap-1 rounded-sm border border-dashed border-border px-2.5 py-1.5 text-[11px] text-fg-3 transition-colors hover:border-border-strong"
                >
                    <X size={11} />
                    Limpiar
                </button>
            )}
        </div>
    );
}

// ---- InboxFooter ----

function InboxFooter({ count, total }: { count: number; total: number }) {
    return (
        <div className="flex shrink-0 items-center justify-between border-t border-border bg-surface-1 px-5 py-2">
            <span className="text-[11px] text-fg-3">
                {count} de {total} incidentes
            </span>
            <div className="flex items-center gap-2 font-mono text-[10px] text-fg-3">
                <span className="sam-kbd">J</span>
                <span className="sam-kbd">K</span>
                <span>navegar</span>
                <span className="sam-kbd ml-2">A</span>
                <span>asignar</span>
                <span className="sam-kbd ml-2">X</span>
                <span>seleccionar</span>
                <span className="sam-kbd ml-2">Enter</span>
                <span>abrir</span>
            </div>
        </div>
    );
}

// ---- Empty state ----

function InboxEmptyState() {
    return (
        <div className="flex min-h-0 flex-1 flex-col items-center justify-center gap-3 px-6 py-16 text-center">
            <div className="inline-grid size-12 place-items-center rounded-full border border-border bg-surface-2 text-fg-3">
                <Inbox size={22} strokeWidth={1.5} />
            </div>
            <h2 className="text-[15px] font-semibold text-fg-1">
                Sin incidentes
            </h2>
            <p className="max-w-sm text-[12px] leading-[1.5] text-fg-3">
                Cuando el pipeline genere incidentes para tu equipo aparecerán
                aquí en tiempo real.
            </p>
        </div>
    );
}

// ---- Detail placeholder (shown while the panel payload loads) ----

function DetailPlaceholder({
    loading,
    onClose,
}: {
    loading: boolean;
    onClose: () => void;
}) {
    return (
        <div className="relative flex min-w-0 flex-col items-center justify-center gap-3 border-l border-border bg-background p-8 text-center">
            <button
                type="button"
                onClick={onClose}
                className="absolute top-3 right-3 text-fg-3 hover:text-fg-1"
                aria-label="Cerrar detalle"
            >
                <X size={16} />
            </button>
            {loading ? (
                <>
                    <Loader2 size={22} className="animate-spin text-fg-3" />
                    <span className="text-[12px] text-fg-3">
                        Cargando detalle…
                    </span>
                </>
            ) : (
                <span className="text-[12px] text-fg-3">
                    No se pudo cargar el detalle del incidente.
                </span>
            )}
        </div>
    );
}

// ---- Main page ----

interface IncidentsIndexProps {
    incidents: MockIncident[];
    filters: InboxFilters;
    filterOptions: InboxFilterOptions;
}

const EMPTY_FILTERS: InboxFilters = {
    q: null,
    severity: null,
    status: null,
    provider: null,
    shift: null,
};

const EMPTY_OPTIONS: InboxFilterOptions = {
    severities: [],
    statuses: [],
    providers: [],
    shifts: [],
};

export default function IncidentsIndex() {
    const page = usePage();
    const pageProps = page.props as unknown as IncidentsIndexProps;
    const incidents = useMemo(
        () => pageProps.incidents ?? [],
        [pageProps.incidents],
    );
    const serverFilters = pageProps.filters ?? EMPTY_FILTERS;
    const filterOptions = pageProps.filterOptions ?? EMPTY_OPTIONS;
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const getInitials = useInitials();
    const currentUserName = page.props.auth?.user?.name ?? null;
    const currentUserId =
        (page.props.auth?.user?.id as number | undefined) ?? null;
    const myInitials = currentUserName ? getInitials(currentUserName) : null;

    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [layout, setLayout] = useState<InboxLayout>('table');
    const [density, setDensity] = useState<InboxDensity>('comfortable');
    const [tab, setTab] = useState<InboxTab>('open');
    const [selectedSet, setSelectedSet] = useState<Set<string>>(new Set());
    const [detailCache, setDetailCache] = useState<
        Record<string, IncidentDetail>
    >({});
    const [failedIds, setFailedIds] = useState<Set<string>>(new Set());
    const [refreshing, setRefreshing] = useState(false);
    const [filters, setFilters] = useState<InboxFilters>(serverFilters);
    const [bulkPending, setBulkPending] = useState<string | null>(null);
    const [assigningOldest, setAssigningOldest] = useState(false);

    // Re-sync local filter state if the server echoes a different set
    // (e.g. after a browser back/forward navigation).
    useEffect(() => {
        setFilters(serverFilters);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        serverFilters.q,
        serverFilters.severity,
        serverFilters.status,
        serverFilters.provider,
        serverFilters.shift,
    ]);

    const openIncidents = useMemo(
        () =>
            incidents.filter(
                (i) => !['resolved', 'closed', 'discarded'].includes(i.status),
            ),
        [incidents],
    );

    const critical = openIncidents.filter(
        (i) => i.severity === 'critical',
    ).length;

    const rows = useMemo(() => {
        let source: MockIncident[];

        switch (tab) {
            case 'open':
                source = openIncidents;
                break;
            case 'mine':
                source = incidents.filter(
                    (i) =>
                        myInitials !== null &&
                        i.assignee?.initials === myInitials,
                );
                break;
            case 'unassigned':
                source = openIncidents.filter((i) => !i.assignee);
                break;
            case 'sla':
                source = openIncidents
                    .filter((i) => i.slaSeconds < 900)
                    .sort((a, b) => a.slaSeconds - b.slaSeconds);
                break;
            case 'discarded':
                source = incidents.filter((i) => i.status === 'discarded');
                break;
            default:
                source = incidents;
        }

        if (tab !== 'sla') {
            return [...source].sort((a, b) => a.slaSeconds - b.slaSeconds);
        }

        return source;
    }, [tab, openIncidents, incidents, myInitials]);

    const selectedRow = useMemo(
        () => incidents.find((i) => i.id === selectedId) ?? null,
        [incidents, selectedId],
    );
    const selectedDetail = selectedId
        ? (detailCache[selectedId] ?? null)
        : null;
    const detailFailed = selectedId !== null && failedIds.has(selectedId);
    const detailLoading =
        selectedId !== null && selectedDetail === null && !detailFailed;

    // Fetch the full detail payload for the selected row on demand. State is
    // only mutated inside the async callbacks, never synchronously.
    useEffect(() => {
        if (selectedId === null || selectedRow === null || teamSlug === null) {
            return;
        }

        if (detailCache[selectedId] || failedIds.has(selectedId)) {
            return;
        }

        const controller = new AbortController();

        fetch(`/${teamSlug}/incidents/${selectedRow.incidentId}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((res) =>
                res.ok
                    ? (res.json() as Promise<IncidentDetail>)
                    : Promise.reject(res),
            )
            .then((data) => {
                setDetailCache((prev) => ({ ...prev, [selectedId]: data }));
            })
            .catch((error: unknown) => {
                if (
                    error instanceof DOMException &&
                    error.name === 'AbortError'
                ) {
                    return;
                }

                setFailedIds((prev) => new Set(prev).add(selectedId));
            });

        return () => controller.abort();
    }, [selectedId, selectedRow, teamSlug, detailCache, failedIds]);

    const refresh = () => {
        setRefreshing(true);
        router.reload({
            only: ['incidents'],
            onFinish: () => {
                setRefreshing(false);
                setDetailCache({});
                setFailedIds(new Set());
            },
        });
    };

    const applyFilters = useCallback((next: InboxFilters) => {
        setFilters(next);
        router.reload({
            only: ['incidents', 'filters'],
            data: {
                q: next.q ?? undefined,
                severity: next.severity ?? undefined,
                status: next.status ?? undefined,
                provider: next.provider ?? undefined,
                shift: next.shift ?? undefined,
            },
        });
    }, []);

    // Invalidate the cached detail for the open incident and refresh the list
    // after a panel action mutates server state.
    const handlePanelMutated = useCallback(() => {
        router.reload({ only: ['incidents'] });

        if (selectedId !== null) {
            setDetailCache((prev) => {
                const next = { ...prev };
                delete next[selectedId];

                return next;
            });
            setFailedIds((prev) => {
                const next = new Set(prev);
                next.delete(selectedId);

                return next;
            });
        }
    }, [selectedId]);

    // Live updates: a freshly created incident refreshes the inbox list.
    useEffect(() => {
        const handler = (event: Event) => {
            const detail = (event as CustomEvent<TeamBroadcastDetail>).detail;

            if (detail?.event === 'incidents.created') {
                router.reload({ only: ['incidents'] });
            }
        };

        window.addEventListener(TEAM_BROADCAST_EVENT_NAME, handler);

        return () =>
            window.removeEventListener(TEAM_BROADCAST_EVENT_NAME, handler);
    }, []);

    const handleToggle = (id: string) => {
        setSelectedSet((prev) => {
            const next = new Set(prev);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    const handleSelectAll = () => {
        if (selectedSet.size === rows.length) {
            setSelectedSet(new Set());
        } else {
            setSelectedSet(new Set(rows.map((r) => r.id)));
        }
    };

    const handleSelect = (id: string) => {
        setSelectedId((prev) => (prev === id ? null : id));
    };

    // Run a bulk action over the current selection, then refresh + clear.
    const runBulk = useCallback(
        async (
            key: string,
            buildBody: (incident: MockIncident) => Record<string, unknown>,
            path: string,
            verb: string,
        ) => {
            if (teamSlug === null) {
                toast.error('No hay equipo activo.');

                return;
            }

            const targets = incidents.filter((i) => selectedSet.has(i.id));

            if (targets.length === 0) {
                return;
            }

            setBulkPending(key);

            const results = await Promise.allSettled(
                targets.map((incident) =>
                    postJson(
                        `/${teamSlug}/incidents/${incident.incidentId}/${path}`,
                        buildBody(incident),
                    ),
                ),
            );

            const ok = results.filter(
                (r) => r.status === 'fulfilled' && r.value.ok,
            ).length;
            const failed = targets.length - ok;

            setBulkPending(null);
            setSelectedSet(new Set());

            if (ok > 0) {
                toast.success(`${ok} ${verb}.`);
            }

            if (failed > 0) {
                toast.error(`${failed} no se pudieron procesar.`);
            }

            router.reload({ only: ['incidents'] });
        },
        [incidents, selectedSet, teamSlug],
    );

    const bulkAssign = () => {
        if (currentUserId === null) {
            toast.error('No se pudo identificar tu usuario.');

            return;
        }

        void runBulk(
            'assign',
            () => ({ assigned_to_type: 'user', assigned_to_id: currentUserId }),
            'assign',
            'asignados',
        );
    };

    const bulkEscalate = () =>
        void runBulk('escalate', () => ({}), 'escalate', 'escalados');

    const bulkDiscard = () =>
        void runBulk(
            'discard',
            () => ({
                resolution_code: 'false_positive',
                summary: 'Descartado por el operador.',
            }),
            'resolve',
            'descartados',
        );

    const assignOldestCritical = async () => {
        if (teamSlug === null) {
            toast.error('No hay equipo activo.');

            return;
        }

        if (currentUserId === null) {
            toast.error('No se pudo identificar tu usuario.');

            return;
        }

        const candidates = openIncidents.filter(
            (i) => i.severity === 'critical',
        );

        if (candidates.length === 0) {
            toast('No hay incidentes críticos abiertos.');

            return;
        }

        const oldest = candidates.reduce((a, b) =>
            a.ageMin >= b.ageMin ? a : b,
        );

        setAssigningOldest(true);

        try {
            const response = await postJson(
                `/${teamSlug}/incidents/${oldest.incidentId}/assign`,
                { assigned_to_type: 'user', assigned_to_id: currentUserId },
            );

            if (response.ok) {
                toast.success(`Te asignaste ${oldest.id}.`);
                router.reload({ only: ['incidents'] });
            } else if (response.status === 403) {
                toast.error('No tienes permisos para asignar.');
            } else {
                const message = await readErrorMessage(response);
                toast.error(message ?? 'No se pudo asignar el incidente.');
            }
        } catch {
            toast.error('Error de red. Vuelve a intentarlo.');
        } finally {
            setAssigningOldest(false);
        }
    };

    const hasIncidents = incidents.length > 0;

    return (
        <>
            <Head title="Incidentes" />
            <div
                className={cn(
                    'flex min-h-0 flex-1 overflow-hidden',
                    selectedId !== null
                        ? 'grid grid-cols-[1fr_minmax(520px,700px)]'
                        : '',
                )}
            >
                {/* INBOX PANEL */}
                <div className="flex min-h-0 min-w-0 flex-col overflow-hidden">
                    {selectedSet.size > 0 && (
                        <BulkBar
                            count={selectedSet.size}
                            pending={bulkPending}
                            onAssign={bulkAssign}
                            onEscalate={bulkEscalate}
                            onDiscard={bulkDiscard}
                            onClear={() => setSelectedSet(new Set())}
                        />
                    )}

                    <PageHead
                        openCount={openIncidents.length}
                        criticalCount={critical}
                        layout={layout}
                        setLayout={setLayout}
                        onRefresh={refresh}
                        refreshing={refreshing}
                        onAssignOldestCritical={() =>
                            void assignOldestCritical()
                        }
                        assigningOldest={assigningOldest}
                    />

                    <TabBar
                        tab={tab}
                        setTab={setTab}
                        density={density}
                        setDensity={setDensity}
                        openIncidents={openIncidents}
                    />

                    <FilterBar
                        filters={filters}
                        options={filterOptions}
                        onApply={applyFilters}
                    />

                    {!hasIncidents ? (
                        <InboxEmptyState />
                    ) : (
                        <>
                            {layout === 'table' && (
                                <InboxTable
                                    rows={rows}
                                    selectedId={selectedId}
                                    selectedSet={selectedSet}
                                    density={density}
                                    onSelect={handleSelect}
                                    onToggle={handleToggle}
                                    onSelectAll={handleSelectAll}
                                    allChecked={
                                        rows.length > 0 &&
                                        selectedSet.size === rows.length
                                    }
                                />
                            )}
                            {layout === 'grouped' && (
                                <InboxGrouped
                                    rows={rows}
                                    selectedId={selectedId}
                                    selectedSet={selectedSet}
                                    density={density}
                                    onSelect={handleSelect}
                                    onToggle={handleToggle}
                                />
                            )}
                            {layout === 'stream' && (
                                <InboxStream
                                    rows={rows}
                                    selectedId={selectedId}
                                    onSelect={handleSelect}
                                />
                            )}
                        </>
                    )}

                    <InboxFooter count={rows.length} total={incidents.length} />
                </div>

                {/* DETAIL PANEL */}
                {selectedId !== null &&
                    (selectedDetail ? (
                        <IncidentDetailPanel
                            incident={selectedDetail}
                            onClose={() => setSelectedId(null)}
                            onMutated={handlePanelMutated}
                        />
                    ) : (
                        <DetailPlaceholder
                            loading={detailLoading}
                            onClose={() => setSelectedId(null)}
                        />
                    ))}
            </div>
        </>
    );
}

IncidentsIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Incidentes',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/incidents`
                : '/incidents',
        },
    ],
});
