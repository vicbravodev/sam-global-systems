import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Camera,
    Eye,
    EyeOff,
    Map as MapIcon,
    Navigation,
    Radio,
    RadioTower,
    RefreshCw,
    Siren,
    Truck,
    Wrench,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { AssetsTable } from '@/components/sam/assets/assets-table';
import {
    ClearFiltersButton,
    FilterDropdown,
    ListFooter,
    SearchInput,
} from '@/components/sam/list';
import { PulseStat, PulseStrip } from '@/components/sam/pulse-strip';
import { SegmentedFilter } from '@/components/sam/segmented-filter';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { TEAM_BROADCAST_EVENT_NAME } from '@/hooks/use-team-broadcasts';
import type { TeamBroadcastDetail } from '@/hooks/use-team-broadcasts';
import { cn } from '@/lib/utils';
import type {
    AssetFilterOptions,
    AssetFilters,
    AssetRow,
    AssetsIndexProps,
    AssetsPagination,
    AssetsSummary,
    MonitoringSummary,
} from '@/types/assets';
import type {
    FleetPosition,
    FleetPositionsUpdatedPayload,
} from '@/types/realtime';

// Broadcast events that refresh the fleet list, debounced below. Positions do
// not: they arrive every few seconds and are applied to the rows in memory.
const RELOAD_EVENTS = new Set([
    'asset.location_updated',
    'asset.status_changed',
    'asset.monitoring_changed',
]);

const RELOAD_DEBOUNCE_MS = 2000;

// The pulse strip (moving / reporting counts) follows live positions, but a
// server roundtrip every feed cycle would be waste: at most this often.
const SUMMARY_REFRESH_MS = 30_000;

/**
 * A row with the newest live position laid over it, when that position is
 * newer than what the server rendered.
 */
function withLivePosition(
    asset: AssetRow,
    live: FleetPosition | undefined,
): AssetRow {
    if (
        live === undefined ||
        (asset.lastLocation !== null &&
            Date.parse(live.recorded_at) <=
                Date.parse(asset.lastLocation.recordedAt))
    ) {
        return asset;
    }

    const signal =
        asset.lastSignalAt === null ||
        Date.parse(live.recorded_at) > Date.parse(asset.lastSignalAt)
            ? live.recorded_at
            : asset.lastSignalAt;

    return {
        ...asset,
        lastLocation: {
            latitude: live.latitude,
            longitude: live.longitude,
            formattedLocation: asset.lastLocation?.formattedLocation ?? null,
            speed: live.speed_kph,
            heading: live.heading,
            recordedAt: live.recorded_at,
        },
        currentSpeed:
            live.speed_kph === null
                ? asset.currentSpeed
                : {
                      kph: live.speed_kph,
                      recordedAt: live.recorded_at,
                      source: 'location',
                      stale: false,
                  },
        lastSignalAt: signal,
    };
}

const STATUS_DOT: Record<string, string> = {
    active: 'bg-severity-low',
    inactive: 'bg-fg-3',
    offline: 'bg-fg-3',
    alert: 'bg-severity-high',
    critical: 'bg-severity-critical',
    maintenance: 'bg-severity-medium',
};

// ---- PageHead ----

function PageHead({
    total,
    reporting,
    teamSlug,
    onRefresh,
    refreshing,
}: {
    total: number;
    reporting: number | null;
    teamSlug: string | null;
    onRefresh: () => void;
    refreshing: boolean;
}) {
    return (
        <PageHeader
            title="Flota"
            meta={
                <span className="text-xs text-fg-3">
                    <span className="font-medium text-fg-1">{total}</span>{' '}
                    {total === 1 ? 'unidad' : 'unidades'}
                    {reporting !== null && (
                        <>
                            {' · '}
                            <span className="text-severity-low">
                                {reporting} reportando ahora
                            </span>
                        </>
                    )}
                </span>
            }
            actions={
                <>
                    {teamSlug && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/${teamSlug}/assets/map`}>
                                <MapIcon size={13} />
                                Mapa en vivo
                            </Link>
                        </Button>
                    )}
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
                </>
            }
            className="shrink-0 border-b border-border bg-surface-1 px-5 py-3"
        />
    );
}

// ---- Pending banner ----

function PendingBanner({
    monitoring,
    teamSlug,
    onShowPending,
    onMonitorAll,
    busy,
}: {
    monitoring: MonitoringSummary;
    teamSlug: string | null;
    onShowPending: () => void;
    onMonitorAll: () => void;
    busy: boolean;
}) {
    if (monitoring.pending === 0) {
        return null;
    }

    const capText =
        monitoring.cap === null
            ? `Vigilas ${monitoring.monitored} unidades, sin tope contratado.`
            : `Vigilas ${monitoring.monitored} de ${monitoring.cap} contratadas.` +
              (monitoring.monitored + monitoring.pending > monitoring.cap
                  ? ' Encender más allá del tope se cobra como extra por cada día encendida.'
                  : ' Aún tienes cupo dentro de lo contratado.');

    return (
        <div className="flex shrink-0 flex-col gap-2 border-b border-severity-medium/40 bg-severity-medium/10 px-5 py-2.5 text-xs text-fg-2 sm:flex-row sm:items-center sm:justify-between">
            <p>
                <span className="font-medium text-fg-1">
                    {monitoring.pending}{' '}
                    {monitoring.pending === 1
                        ? 'unidad nueva sin vigilar'
                        : 'unidades nuevas sin vigilar'}
                    .
                </span>{' '}
                SAM no las vigila ni las cobra hasta que las enciendas.{' '}
                {capText}
            </p>
            <div className="flex shrink-0 items-center gap-2">
                <Button variant="outline" size="sm" onClick={onShowPending}>
                    <EyeOff size={13} />
                    Ver pendientes
                </Button>
                {teamSlug && (
                    <Button size="sm" onClick={onMonitorAll} disabled={busy}>
                        <Eye size={13} />
                        Vigilar todas ({monitoring.pending})
                    </Button>
                )}
            </div>
        </div>
    );
}

// ---- Pulse strip ----

function FleetPulse({
    summary,
    monitoring,
    status,
    monitoringFilter,
    onStatus,
    onMonitoring,
}: {
    summary: AssetsSummary;
    monitoring: MonitoringSummary | null;
    status: string | null;
    monitoringFilter: string | null;
    onStatus: (value: string | null) => void;
    onMonitoring: (value: string | null) => void;
}) {
    const toggle = (value: string) => () =>
        onStatus(status === value ? null : value);
    const toggleMonitoring = (value: string) => () =>
        onMonitoring(monitoringFilter === value ? null : value);

    return (
        <PulseStrip>
            <PulseStat
                label="Flota"
                value={summary.total}
                icon={Truck}
                hint="unidades registradas"
                onClick={() => onStatus(null)}
                active={status === null}
            />
            {monitoring && (
                <PulseStat
                    label="Vigiladas"
                    value={monitoring.monitored}
                    icon={Eye}
                    tone={monitoring.overCap ? 'warn' : 'ok'}
                    hint={
                        monitoring.cap === null
                            ? 'sin tope contratado'
                            : monitoring.overCap
                              ? `${monitoring.monitored - monitoring.cap} por encima del tope de ${monitoring.cap} (se cobra extra)`
                              : `de ${monitoring.cap} contratadas`
                    }
                    onClick={toggleMonitoring('monitored')}
                    active={monitoringFilter === 'monitored'}
                />
            )}
            {monitoring && (
                <PulseStat
                    label="Sin vigilar"
                    value={monitoring.pending}
                    icon={EyeOff}
                    tone={monitoring.pending > 0 ? 'warn' : 'neutral'}
                    hint="nuevas, tú decides si se vigilan"
                    onClick={toggleMonitoring('pending')}
                    active={monitoringFilter === 'pending'}
                />
            )}
            <PulseStat
                label="Reportando"
                value={summary.reporting}
                icon={RadioTower}
                tone="ok"
                live={summary.reporting > 0}
                hint="señal en los últimos 15 min"
            />
            <PulseStat
                label="En ruta"
                value={summary.moving}
                icon={Navigation}
                tone="info"
                live={summary.moving > 0}
                hint="en movimiento ahora"
            />
            <PulseStat
                label="Sin señal"
                value={summary.silent}
                icon={Radio}
                tone={summary.silent > 0 ? 'warn' : 'neutral'}
                hint="más de 24 h calladas"
            />
            <PulseStat
                label="Alerta o crítico"
                value={summary.alerting}
                icon={Siren}
                tone={summary.alerting > 0 ? 'critical' : 'neutral'}
                hint={`${summary.statuses.alert} alerta · ${summary.statuses.critical} crítico`}
                onClick={toggle('alert')}
                active={status === 'alert'}
            />
            <PulseStat
                label="Mantenimiento"
                value={summary.maintenance}
                icon={Wrench}
                tone={summary.maintenance > 0 ? 'warn' : 'neutral'}
                hint="fuera de operación"
                onClick={toggle('maintenance')}
                active={status === 'maintenance'}
            />
            <PulseStat
                label="Con cámara"
                value={summary.withCamera}
                icon={Camera}
                hint="dashcam vinculada"
            />
        </PulseStrip>
    );
}

// ---- FilterBar ----

interface FilterBarProps {
    filters: AssetFilters;
    options: AssetFilterOptions;
    summary: AssetsSummary | null;
    onApply: (next: AssetFilters) => void;
}

function FilterBar({ filters, options, summary, onApply }: FilterBarProps) {
    const hasActive =
        filters.q !== null ||
        filters.status !== null ||
        filters.type !== null ||
        filters.monitoring !== null;

    return (
        <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-border bg-background px-5 py-2">
            <SearchInput
                value={filters.q}
                onApply={(q) => onApply({ ...filters, q })}
                placeholder="Buscar por nombre o código…"
                className="mr-1"
            />

            {summary ? (
                <SegmentedFilter
                    aria-label="Filtrar por estado"
                    value={filters.status}
                    onChange={(status) => onApply({ ...filters, status })}
                    allLabel="Todas"
                    allCount={summary.total}
                    options={options.statuses
                        .filter(
                            (o) =>
                                summary.statuses[
                                    o.value as keyof AssetsSummary['statuses']
                                ] > 0 || o.value === filters.status,
                        )
                        .map((o) => ({
                            value: o.value,
                            label: o.label,
                            count: summary.statuses[
                                o.value as keyof AssetsSummary['statuses']
                            ],
                            dot: STATUS_DOT[o.value],
                        }))}
                />
            ) : (
                <FilterDropdown
                    label="Estado"
                    value={filters.status}
                    options={options.statuses}
                    onChange={(status) => onApply({ ...filters, status })}
                />
            )}

            {options.types.length > 1 && (
                <FilterDropdown
                    label="Tipo"
                    value={filters.type}
                    options={options.types}
                    onChange={(type) => onApply({ ...filters, type })}
                />
            )}

            <FilterDropdown
                label="Vigilancia"
                value={filters.monitoring}
                options={options.monitoring}
                onChange={(monitoring) => onApply({ ...filters, monitoring })}
            />

            {hasActive && (
                <ClearFiltersButton
                    onClick={() =>
                        onApply({
                            q: null,
                            status: null,
                            type: null,
                            monitoring: null,
                        })
                    }
                />
            )}
        </div>
    );
}

// ---- Empty state ----

function FleetEmptyState({ filtered }: { filtered: boolean }) {
    return (
        <EmptyState
            className="min-h-0 flex-1"
            icon={Truck}
            title={filtered ? 'Sin resultados' : 'Sin unidades'}
            description={
                filtered
                    ? 'Ninguna unidad coincide con los filtros aplicados.'
                    : 'Cuando la sincronización de integraciones registre vehículos aparecerán aquí.'
            }
        />
    );
}

// ---- Main page ----

const EMPTY_FILTERS: AssetFilters = {
    q: null,
    status: null,
    type: null,
    monitoring: null,
};

const EMPTY_OPTIONS: AssetFilterOptions = {
    statuses: [],
    types: [],
    monitoring: [],
};

const EMPTY_PAGINATION: AssetsPagination = {
    page: 1,
    perPage: 50,
    total: 0,
    lastPage: 1,
};

export default function AssetsIndex() {
    const page = usePage();
    const pageProps = page.props as unknown as AssetsIndexProps;
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const serverAssets = pageProps.assets;
    const [livePositions, setLivePositions] = useState<
        Map<number, FleetPosition>
    >(() => new Map());
    const assets = useMemo(
        () =>
            (serverAssets ?? []).map((asset) =>
                withLivePosition(asset, livePositions.get(asset.id)),
            ),
        [serverAssets, livePositions],
    );
    const pagination = pageProps.pagination ?? EMPTY_PAGINATION;
    const serverFilters = pageProps.filters ?? EMPTY_FILTERS;
    const filterOptions = pageProps.filterOptions ?? EMPTY_OPTIONS;
    const summary = pageProps.summary ?? null;
    const monitoring = pageProps.monitoring ?? null;

    const [refreshing, setRefreshing] = useState(false);
    const [monitoringAll, setMonitoringAll] = useState(false);
    const [filters, setFilters] = useState<AssetFilters>(serverFilters);

    // Re-sync local filter state if the server echoes a different set
    // (e.g. after a browser back/forward navigation).
    useEffect(() => {
        setFilters(serverFilters);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        serverFilters.q,
        serverFilters.status,
        serverFilters.type,
        serverFilters.monitoring,
    ]);

    const refresh = () => {
        setRefreshing(true);
        router.reload({
            only: ['assets', 'pagination', 'summary', 'monitoring'],
            onFinish: () => setRefreshing(false),
        });
    };

    const applyFilters = useCallback((next: AssetFilters) => {
        setFilters(next);
        router.reload({
            only: ['assets', 'pagination', 'filters'],
            data: {
                q: next.q ?? undefined,
                status: next.status ?? undefined,
                type: next.type ?? undefined,
                monitoring: next.monitoring ?? undefined,
                // Changing filters always restarts at the first page.
                page: undefined,
            },
        });
    }, []);

    // "Vigilar todas": enciende cada unidad pendiente. El servidor avisa si
    // con eso se rebasa el tope (se cobra como extra, no se bloquea).
    const monitorAllPending = useCallback(() => {
        if (teamSlug === null) {
            return;
        }

        setMonitoringAll(true);
        router.reload({
            only: ['assets'],
            data: { monitoring: 'pending', page: undefined },
            onSuccess: (page) => {
                const pending = (
                    (page.props as unknown as AssetsIndexProps).assets ?? []
                ).map((asset) => asset.id);

                if (pending.length === 0) {
                    setMonitoringAll(false);

                    return;
                }

                router.put(
                    `/${teamSlug}/assets/monitoring`,
                    { state: 'monitored', asset_ids: pending },
                    {
                        preserveScroll: true,
                        onSuccess: (result) => {
                            const flash = (
                                result.props as {
                                    flash?: { status?: string | null };
                                }
                            ).flash;
                            toast.success(
                                flash?.status ?? 'Unidades encendidas.',
                            );
                        },
                        onError: () =>
                            toast.error(
                                'No se pudieron encender las unidades.',
                            ),
                        onFinish: () => setMonitoringAll(false),
                    },
                );
            },
            onError: () => setMonitoringAll(false),
        });
    }, [teamSlug]);

    const goToPage = useCallback((target: number) => {
        router.reload({
            only: ['assets', 'pagination'],
            data: { page: target },
        });
    }, []);

    // Live updates: location polls and status transitions refresh the list
    // and the pulse strip. Bursts are coalesced into a single partial reload.
    const timer = useRef<number | null>(null);
    const lastSummaryRefresh = useRef(0);

    useEffect(() => {
        const handler = (event: Event) => {
            const detail = (event as CustomEvent<TeamBroadcastDetail>).detail;

            if (detail?.event === 'fleet.positions_updated') {
                const { positions } =
                    detail.payload as unknown as FleetPositionsUpdatedPayload;

                setLivePositions((prev) => {
                    const next = new Map(prev);
                    positions.forEach((p) => next.set(p.asset_id, p));

                    return next;
                });

                if (
                    Date.now() - lastSummaryRefresh.current >
                    SUMMARY_REFRESH_MS
                ) {
                    lastSummaryRefresh.current = Date.now();
                    router.reload({ only: ['summary', 'monitoring'] });
                }

                return;
            }

            if (!RELOAD_EVENTS.has(detail?.event ?? '')) {
                return;
            }

            if (timer.current !== null) {
                return;
            }

            timer.current = window.setTimeout(() => {
                timer.current = null;
                router.reload({
                    only: ['assets', 'pagination', 'summary', 'monitoring'],
                });
            }, RELOAD_DEBOUNCE_MS);
        };

        window.addEventListener(TEAM_BROADCAST_EVENT_NAME, handler);

        return () => {
            window.removeEventListener(TEAM_BROADCAST_EVENT_NAME, handler);

            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        };
    }, []);

    const hasActiveFilters =
        serverFilters.q !== null ||
        serverFilters.status !== null ||
        serverFilters.type !== null ||
        serverFilters.monitoring !== null;

    const handleSelect = useCallback(
        (id: number) => {
            if (teamSlug !== null) {
                router.visit(`/${teamSlug}/assets/${id}`);
            }
        },
        [teamSlug],
    );

    return (
        <>
            <Head title="Flota" />
            <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
                <PageHead
                    total={summary?.total ?? pagination.total}
                    reporting={summary?.reporting ?? null}
                    teamSlug={teamSlug}
                    onRefresh={refresh}
                    refreshing={refreshing}
                />

                {monitoring && (
                    <PendingBanner
                        monitoring={monitoring}
                        teamSlug={teamSlug}
                        busy={monitoringAll}
                        onShowPending={() =>
                            applyFilters({ ...filters, monitoring: 'pending' })
                        }
                        onMonitorAll={monitorAllPending}
                    />
                )}

                {summary && (
                    <FleetPulse
                        summary={summary}
                        monitoring={monitoring}
                        status={filters.status}
                        monitoringFilter={filters.monitoring}
                        onStatus={(status) =>
                            applyFilters({ ...filters, status })
                        }
                        onMonitoring={(value) =>
                            applyFilters({ ...filters, monitoring: value })
                        }
                    />
                )}

                <FilterBar
                    filters={filters}
                    options={filterOptions}
                    summary={summary}
                    onApply={applyFilters}
                />

                <AssetsTable
                    rows={assets}
                    onSelect={handleSelect}
                    teamSlug={teamSlug}
                    empty={<FleetEmptyState filtered={hasActiveFilters} />}
                />

                <ListFooter
                    pagination={pagination}
                    shown={assets.length}
                    onPage={goToPage}
                    noun={['unidad', 'unidades']}
                />
            </div>
        </>
    );
}

AssetsIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Flota',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/assets`
                : '/assets',
        },
    ],
});
