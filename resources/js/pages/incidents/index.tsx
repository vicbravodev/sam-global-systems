import type { SharedPageProps } from '@inertiajs/core';
import { Head, router, usePage } from '@inertiajs/react';
import { Inbox, LayoutList, Loader2, Rows3, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { DetailResizer } from '@/components/sam/detail-resizer';
import { InboxGrouped } from '@/components/sam/inbox/inbox-grouped';
import { InboxStream } from '@/components/sam/inbox/inbox-stream';
import { InboxTable } from '@/components/sam/inbox/inbox-table';
import { IncidentDetailPanel } from '@/components/sam/incident-detail';
import {
    ClearFiltersButton,
    FilterDropdown,
    SearchInput,
} from '@/components/sam/list';
import { RefreshButton } from '@/components/sam/list-page';
import { PermissionTooltip } from '@/components/sam/permission-tooltip';
import { TabBar } from '@/components/sam/tab-bar';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { hasActiveFilters, useServerList } from '@/hooks/use-server-list';
import {
    useBroadcastReload,
    useTeamBroadcast,
} from '@/hooks/use-team-broadcasts';
import { getJson, postJson, readErrorMessage } from '@/lib/sam-fetch';
import { cn } from '@/lib/utils';
import incidentRoutes from '@/routes/incidents';
import type {
    InboxDensity,
    InboxFilterOptions,
    InboxFilters,
    InboxLayout,
    InboxTab,
    IncidentAbilities,
    IncidentDetail,
    MockIncident,
} from '@/types/sam';

// ---- BulkBar ----

interface BulkBarProps {
    count: number;
    pending: string | null;
    canManage: boolean;
    canResolve: boolean;
    onAssign: () => void;
    onEscalate: () => void;
    onDiscard: () => void;
    onClear: () => void;
}

function BulkBar({
    count,
    pending,
    canManage,
    canResolve,
    onAssign,
    onEscalate,
    onDiscard,
    onClear,
}: BulkBarProps) {
    const busy = pending !== null;

    return (
        <div className="flex shrink-0 items-center gap-2.5 border-b border-border bg-primary/18 px-5 py-2">
            <span className="text-xs font-semibold text-primary">
                {count} seleccionados
            </span>
            {canManage && (
                <>
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
                </>
            )}
            {canResolve && (
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
            )}
            {!canManage && !canResolve && (
                <span className="text-xs text-fg-3">
                    Tu rol no permite acciones en lote.
                </span>
            )}
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
    canAssign: boolean;
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
    canAssign,
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
        <PageHeader
            title="Bandeja de incidentes"
            meta={
                <div className="flex items-center gap-2 text-xs text-fg-3">
                    <span>
                        <span className="font-medium text-fg-1">
                            {openCount}
                        </span>{' '}
                        abiertos
                    </span>
                    <span>·</span>
                    <span className="flex items-center gap-1">
                        <span className="relative inline-flex size-1.5">
                            <span className="absolute inline-flex h-full w-full rounded-full bg-severity-critical opacity-60 motion-safe:animate-ping" />
                            <span className="relative inline-flex size-1.5 rounded-full bg-severity-critical" />
                        </span>
                        <span className="font-medium text-severity-critical">
                            {criticalCount}
                        </span>{' '}
                        críticos
                    </span>
                </div>
            }
            actions={
                <>
                    {/* Layout switcher */}
                    <div className="flex items-center gap-0.5 rounded-md border border-border bg-surface-2 p-0.5">
                        {layouts.map((l) => (
                            <button
                                key={l.value}
                                type="button"
                                onClick={() => setLayout(l.value)}
                                className={cn(
                                    'inline-flex items-center gap-1 rounded-sm px-2 py-1 text-2xs font-medium transition-colors',
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

                    <RefreshButton
                        onClick={onRefresh}
                        refreshing={refreshing}
                    />

                    {!canAssign ? (
                        <PermissionTooltip
                            allowed={false}
                            reason="Tu rol no permite asignar incidentes."
                        >
                            <Button variant="outline" size="sm" disabled>
                                Asignarme crítico más viejo
                            </Button>
                        </PermissionTooltip>
                    ) : criticalCount === 0 ? (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <span tabIndex={0}>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled
                                        className="pointer-events-none"
                                    >
                                        Asignarme crítico más viejo
                                    </Button>
                                </span>
                            </TooltipTrigger>
                            <TooltipContent side="bottom">
                                No hay incidentes críticos abiertos ahora mismo.
                            </TooltipContent>
                        </Tooltip>
                    ) : (
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
                    )}
                </>
            }
            className="shrink-0 border-b border-border bg-background px-5 py-3"
        />
    );
}

// ---- Tabs ----

const TABS: { key: InboxTab; label: string }[] = [
    { key: 'open', label: 'Abiertos' },
    { key: 'mine', label: 'Míos' },
    { key: 'unassigned', label: 'Sin asignar' },
    { key: 'sla', label: 'SLA crítico' },
    { key: 'all', label: 'Todos' },
    { key: 'discarded', label: 'Descartados' },
];

const DENSITY_OPTS: { value: InboxDensity; label: string }[] = [
    { value: 'compact', label: 'C' },
    { value: 'comfortable', label: 'M' },
    { value: 'relaxed', label: 'R' },
];

function DensityToggle({
    density,
    setDensity,
}: {
    density: InboxDensity;
    setDensity: (d: InboxDensity) => void;
}) {
    // En móvil la bandeja usa tarjetas, no filas.
    return (
        <div className="hidden shrink-0 items-center gap-1 py-1.5 sm:flex">
            {DENSITY_OPTS.map((d) => (
                <button
                    key={d.value}
                    type="button"
                    onClick={() => setDensity(d.value)}
                    className={cn(
                        'h-6 w-6 rounded-sm text-3xs font-semibold transition-colors',
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
    );
}

// ---- FilterBar ----

interface FilterBarProps {
    filters: InboxFilters;
    options: InboxFilterOptions;
    onApply: (next: InboxFilters) => void;
    onReset: () => void;
}

function FilterBar({ filters, options, onApply, onReset }: FilterBarProps) {
    const providerOptions = options.providers.map((p) => ({
        value: p,
        label: p,
    }));

    return (
        <div className="scrollbar-none flex shrink-0 items-center gap-2 overflow-x-auto border-b border-border bg-background px-5 py-2">
            <SearchInput
                value={filters.q}
                onApply={(q) => onApply({ ...filters, q })}
                placeholder="Buscar incidente…"
                className="mr-1 shrink-0"
            />

            <FilterDropdown
                label="Severidad"
                value={filters.severity}
                options={options.severities}
                onChange={(v) => onApply({ ...filters, severity: v })}
                className="shrink-0"
            />
            <FilterDropdown
                label="Estado"
                value={filters.status}
                options={options.statuses}
                onChange={(v) => onApply({ ...filters, status: v })}
                className="shrink-0"
            />
            <FilterDropdown
                label="Proveedor"
                value={filters.provider}
                options={providerOptions}
                onChange={(v) => onApply({ ...filters, provider: v })}
                className="shrink-0"
            />
            <FilterDropdown
                label="Turno"
                value={filters.shift}
                options={options.shifts}
                onChange={(v) => onApply({ ...filters, shift: v })}
                className="shrink-0"
            />

            {hasActiveFilters(filters) && (
                <ClearFiltersButton onClick={onReset} className="shrink-0" />
            )}
        </div>
    );
}
// ---- InboxFooter ----

function InboxFooter({
    count,
    total,
    canAssign,
}: {
    count: number;
    total: number;
    canAssign: boolean;
}) {
    return (
        <div className="flex shrink-0 items-center justify-between border-t border-border bg-surface-1 px-5 py-2">
            <span className="text-2xs text-fg-3">
                {count} de {total} incidentes
            </span>
            {/* D2: los atajos de teclado no aplican en táctil; se ocultan en
                pantallas pequeñas. */}
            <div className="hidden items-center gap-2 font-mono text-3xs text-fg-3 md:flex">
                <span className="sam-kbd">J</span>
                <span className="sam-kbd">K</span>
                <span>navegar</span>
                {canAssign && (
                    <>
                        <span className="sam-kbd ml-2">A</span>
                        <span>asignar</span>
                    </>
                )}
                <span className="sam-kbd ml-2">X</span>
                <span>seleccionar</span>
                <span className="sam-kbd ml-2">Enter</span>
                <span>abrir</span>
            </div>
        </div>
    );
}

// ---- Empty state ----

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
                    <span className="text-xs text-fg-3">Cargando detalle…</span>
                </>
            ) : (
                <span className="text-xs text-fg-3">
                    No se pudo cargar el detalle del incidente.
                </span>
            )}
        </div>
    );
}

// ---- Incident actions ----

const NETWORK_ERROR = 'Error de red. Vuelve a intentarlo.';

/**
 * POST de una acción sobre un incidente. `null` = error de red. Vive fuera
 * del componente: el React Compiler aún no compila condicionales dentro de
 * try/catch.
 */
async function postIncidentAction(
    url: string,
    body: Record<string, unknown>,
): Promise<{ ok: boolean; status: number; message: string | null } | null> {
    try {
        const response = await postJson(url, body);

        return {
            ok: response.ok,
            status: response.status,
            message: response.ok ? null : await readErrorMessage(response),
        };
    } catch {
        return null;
    }
}

// ---- Main page ----
interface IncidentsIndexProps {
    incidents: MockIncident[];
    filters: InboxFilters;
    /**
     * Deferred (with `members` and `reclassifyOptions`, read by the detail
     * panel): until it lands the filter triggers render as usual over empty
     * menus, so no skeleton is needed.
     */
    filterOptions?: InboxFilterOptions;
    can?: IncidentAbilities;
}

const NO_ABILITIES: IncidentAbilities = {
    manage: false,
    resolve: false,
    close: false,
    requestMedia: false,
    reevaluate: false,
};

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

// Coalescing window for live inbox reloads.
const INBOX_RELOAD_DEBOUNCE_MS = 1500;

// How long after acting on an incident its broadcast echo is treated as our
// own (the explicit post-action reload already fetched that state). Echoes go
// through the realtime queue, normally well under a second.
const OWN_ECHO_WINDOW_MS = 5000;

/**
 * Whether an `incidents.updated` echo belongs to an action this tab took in
 * the last OWN_ECHO_WINDOW_MS. Expired entries are pruned on the way.
 */
function isOwnEcho(actedUntil: Map<number, number>, incidentId: number) {
    const until = actedUntil.get(incidentId);

    if (until === undefined) {
        return false;
    }

    if (until < Date.now()) {
        actedUntil.delete(incidentId);

        return false;
    }

    return true;
}
export default function IncidentsIndex(pageProps: IncidentsIndexProps) {
    const page = usePage();
    const incidents = useMemo(
        () => pageProps.incidents ?? [],
        [pageProps.incidents],
    );
    const filterOptions = pageProps.filterOptions ?? EMPTY_OPTIONS;
    const can = pageProps.can ?? NO_ABILITIES;
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const currentUserId =
        (page.props.auth?.user?.id as number | undefined) ?? null;

    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [layout, setLayout] = useState<InboxLayout>('table');
    const [density, setDensity] = useState<InboxDensity>('comfortable');
    const [tab, setTab] = useState<InboxTab>('open');
    const [selectedSet, setSelectedSet] = useState<Set<string>>(new Set());
    const [detailCache, setDetailCache] = useState<
        Record<string, IncidentDetail>
    >({});
    const [failedIds, setFailedIds] = useState<Set<string>>(new Set());
    const [bulkPending, setBulkPending] = useState<string | null>(null);
    const [assigningOldest, setAssigningOldest] = useState(false);
    /** Incidente cuya toma/liberación está en vuelo, para bloquear su botón. */
    const [claimPendingId, setClaimPendingId] = useState<number | null>(null);

    const list = useServerList({
        only: ['incidents'],
        filters: pageProps.filters ?? EMPTY_FILTERS,
        emptyFilters: EMPTY_FILTERS,
        // Refrescar también tira los detalles cacheados del panel.
        onRefreshFinish: () => {
            setDetailCache({});
            setFailedIds(new Set());
        },
    });

    const openIncidents = incidents.filter(
        (i) => !['resolved', 'closed', 'discarded'].includes(i.status),
    );

    const critical = openIncidents.filter(
        (i) => i.severity === 'critical',
    ).length;

    const rows = (() => {
        let source: MockIncident[];

        switch (tab) {
            case 'open':
                source = openIncidents;
                break;
            case 'mine':
                source = incidents.filter(
                    (i) =>
                        currentUserId !== null &&
                        i.assignee?.id === currentUserId,
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
    })();

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

        getJson(
            incidentRoutes.show.url([teamSlug, selectedRow.incidentId]),
            controller.signal,
        )
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

    // Live updates: a freshly created or updated incident (status change,
    // claim, media assessed) refreshes the inbox through the shared reload
    // buffer: bursts coalesce into one 200-row reload, a hidden tab waits
    // until it is visible again, a socket drop resyncs.
    //
    // The operator's own actions reload the list right away (instant
    // feedback), so the broadcast echo of that same action would be a second
    // identical 200-row reload: echoes of incidents this tab just acted on
    // are skipped for OWN_ECHO_WINDOW_MS. Actions without an echo (assign,
    // reclassify) are unaffected — their explicit reload is the only one.
    const actedUntil = useRef<Map<number, number>>(new Map());

    const markActed = (incidentIds: readonly number[]) => {
        const until = Date.now() + OWN_ECHO_WINDOW_MS;
        incidentIds.forEach((id) => actedUntil.current.set(id, until));
    };

    const reload = useBroadcastReload(
        {
            'incidents.created': ['incidents'],
            'incidents.updated': (payload) =>
                isOwnEcho(actedUntil.current, payload.incident_id)
                    ? null
                    : ['incidents'],
        },
        { debounceMs: INBOX_RELOAD_DEBOUNCE_MS },
    );

    /**
     * The one list reload after the operator's own action: immediate, and it
     * supersedes any reload already queued (an echo that beat the response).
     */
    const reloadAfterAction = (incidentIds: readonly number[]) => {
        markActed(incidentIds);
        reload.reloadNow(['incidents']);
    };

    // An update to an incident whose detail is cached drops that cache entry
    // so the open panel is not left stale (own echoes included: the panel
    // refetches on its own after a mutation anyway).
    useTeamBroadcast(['incidents.updated'], (detail) => {
        const rowId = incidents.find(
            (row) => row.incidentId === detail.payload.incident_id,
        )?.id;

        if (rowId === undefined) {
            return;
        }

        setDetailCache((prev) => {
            if (!(rowId in prev)) {
                return prev;
            }

            const next = { ...prev };
            delete next[rowId];

            return next;
        });
    });

    // Invalidate the cached detail for the open incident and refresh the list
    // after a panel action mutates server state.
    const handlePanelMutated = () => {
        reloadAfterAction(selectedRow ? [selectedRow.incidentId] : []);

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
    };

    const assignIncidentToMe = async (incident: MockIncident) => {
        if (teamSlug === null || currentUserId === null) {
            return;
        }

        const result = await postIncidentAction(
            incidentRoutes.assign.url([teamSlug, incident.incidentId]),
            { assigned_to_type: 'user', assigned_to_id: currentUserId },
        );

        if (result === null) {
            toast.error(NETWORK_ERROR);
        } else if (result.ok) {
            toast.success(`Te asignaste ${incident.id}.`);
            reloadAfterAction([incident.incidentId]);
        } else {
            toast.error(result.message ?? 'No se pudo asignar el incidente.');
        }
    };

    /**
     * Toma/suelta desde la fila. El 409 no es un error del usuario sino una
     * carrera perdida: se avisa con el mensaje del servidor y se refresca para
     * que la fila pase a mostrar quién ganó.
     */
    const toggleClaim = async (incident: MockIncident) => {
        if (teamSlug === null) {
            return;
        }

        const mine =
            incident.claimedBy !== null &&
            incident.claimedBy.id === currentUserId;
        const action = mine ? incidentRoutes.release : incidentRoutes.claim;

        setClaimPendingId(incident.incidentId);

        const result = await postIncidentAction(
            action.url([teamSlug, incident.incidentId]),
            {},
        );

        if (result === null) {
            toast.error(NETWORK_ERROR);
        } else if (result.ok) {
            toast.success(
                mine ? `Soltaste ${incident.id}.` : `Tomaste ${incident.id}.`,
            );
            reloadAfterAction([incident.incidentId]);
        } else {
            toast.error(result.message ?? 'No se pudo completar la acción.');

            // Lost race: show who won. Not our echo, so nothing is marked.
            if (result.status === 409) {
                reload.reloadNow(['incidents']);
            }
        }

        setClaimPendingId(null);
    };
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
    const runBulk = async (
        key: string,
        buildBody: (incident: MockIncident) => Record<string, unknown>,
        action: typeof incidentRoutes.assign,
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
                    action.url([teamSlug, incident.incidentId]),
                    buildBody(incident),
                ),
            ),
        );

        const succeeded = targets.filter((_, index) => {
            const result = results[index];

            return result?.status === 'fulfilled' && result.value.ok;
        });
        const ok = succeeded.length;
        const failed = targets.length - ok;

        setBulkPending(null);
        setSelectedSet(new Set());

        if (ok > 0) {
            toast.success(`${ok} ${verb}.`);
        }

        if (failed > 0) {
            toast.error(`${failed} no se pudieron procesar.`);
        }

        // One reload for the whole batch; the echoes of the incidents that
        // changed are covered by it.
        reloadAfterAction(succeeded.map((incident) => incident.incidentId));
    };

    const bulkAssign = () => {
        if (currentUserId === null) {
            toast.error('No se pudo identificar tu usuario.');

            return;
        }

        void runBulk(
            'assign',
            () => ({ assigned_to_type: 'user', assigned_to_id: currentUserId }),
            incidentRoutes.assign,
            'asignados',
        );
    };

    const bulkEscalate = () =>
        void runBulk(
            'escalate',
            () => ({}),
            incidentRoutes.escalate,
            'escalados',
        );

    const bulkDiscard = () =>
        void runBulk(
            'discard',
            () => ({
                resolution_code: 'false_positive',
                summary: 'Descartado por el operador.',
            }),
            incidentRoutes.resolve,
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

        const result = await postIncidentAction(
            incidentRoutes.assign.url([teamSlug, oldest.incidentId]),
            { assigned_to_type: 'user', assigned_to_id: currentUserId },
        );

        if (result === null) {
            toast.error(NETWORK_ERROR);
        } else if (result.ok) {
            toast.success(`Te asignaste ${oldest.id}.`);
            reloadAfterAction([oldest.incidentId]);
        } else if (result.status === 403) {
            toast.error('No tienes permisos para asignar.');
        } else {
            toast.error(result.message ?? 'No se pudo asignar el incidente.');
        }

        setAssigningOldest(false);
    };
    // Atajos de teclado que el footer anuncia (F2.2): J/K navegar, Enter
    // abrir, X seleccionar, A asignarme, Esc cerrar el panel. Nunca dentro
    // de inputs ni diálogos.
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
                handleToggle(selectedId);
            } else if (key === 'a' && selectedId !== null && can.manage) {
                e.preventDefault();
                const row = rows.find((r) => r.id === selectedId);

                if (row) {
                    void assignIncidentToMe(row);
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

    const hasIncidents = incidents.length > 0;

    return (
        <>
            <Head title="Incidentes" />
            <div
                className={cn(
                    'flex min-h-0 flex-1 overflow-hidden',
                    selectedId !== null
                        ? 'has-detail md:grid md:grid-cols-[1fr_minmax(520px,700px)]'
                        : '',
                )}
            >
                {/* INBOX PANEL */}
                <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
                    {selectedSet.size > 0 && (
                        <BulkBar
                            count={selectedSet.size}
                            pending={bulkPending}
                            canManage={can.manage}
                            canResolve={can.resolve}
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
                        onRefresh={list.refresh}
                        refreshing={list.refreshing}
                        onAssignOldestCritical={() =>
                            void assignOldestCritical()
                        }
                        assigningOldest={assigningOldest}
                        canAssign={can.manage}
                    />

                    <TabBar
                        aria-label="Vistas de la bandeja"
                        items={TABS.map((t) => ({
                            ...t,
                            count:
                                t.key === 'open' && openIncidents.length > 0
                                    ? openIncidents.length
                                    : undefined,
                        }))}
                        value={tab}
                        onChange={(key) => setTab(key as InboxTab)}
                        actions={
                            <DensityToggle
                                density={density}
                                setDensity={setDensity}
                            />
                        }
                        className="shrink-0 bg-surface-1 px-5"
                    />

                    <FilterBar
                        filters={list.filters}
                        options={filterOptions}
                        onApply={list.apply}
                        onReset={list.reset}
                    />

                    {!hasIncidents ? (
                        <EmptyState
                            className="min-h-0 flex-1"
                            icon={Inbox}
                            title="Sin incidentes"
                            description="Cuando el pipeline genere incidentes para tu equipo aparecerán aquí en tiempo real."
                        />
                    ) : rows.length === 0 ? (
                        <EmptyState
                            className="min-h-0 flex-1"
                            icon={Inbox}
                            title="Nada en esta pestaña"
                            description="No hay incidentes que coincidan con la pestaña o los filtros activos. Cambia de pestaña o limpia los filtros."
                        />
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
                                    currentUserId={currentUserId}
                                    claimPendingId={claimPendingId}
                                    onClaimToggle={
                                        can.manage ? toggleClaim : undefined
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
                                    currentUserId={currentUserId}
                                    claimPendingId={claimPendingId}
                                    onClaimToggle={
                                        can.manage ? toggleClaim : undefined
                                    }
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

                    <InboxFooter
                        count={rows.length}
                        total={incidents.length}
                        canAssign={can.manage}
                    />
                </div>

                {/* DETAIL PANEL — side column on md+, full-screen overlay on
                    mobile. `grid` (not `flex`) so the single child stretches
                    to fill both axes by default, matching what the previous
                    `md:contents` trick gave for free. DetailResizer needs a
                    real box (not `display: contents`) as its parent to read
                    a meaningful width, hence the change. */}
                {selectedId !== null && (
                    <div className="relative grid min-h-0 min-w-0 overflow-hidden max-md:fixed max-md:inset-0 max-md:z-40 max-md:bg-background">
                        <DetailResizer
                            min={420}
                            defaultWidth={700}
                            className="max-md:hidden"
                        />
                        {selectedDetail ? (
                            <IncidentDetailPanel
                                incident={selectedDetail}
                                onClose={() => setSelectedId(null)}
                                onMutated={handlePanelMutated}
                                detailHref={
                                    teamSlug
                                        ? incidentRoutes.show.url([
                                              teamSlug,
                                              selectedDetail.incidentId,
                                          ])
                                        : undefined
                                }
                            />
                        ) : (
                            <DetailPlaceholder
                                loading={detailLoading}
                                onClose={() => setSelectedId(null)}
                            />
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

IncidentsIndex.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'Incidentes',
            href: props.currentTeam
                ? incidentRoutes.index.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
