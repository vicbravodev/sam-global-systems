import type { SharedPageProps } from '@inertiajs/core';
import { Deferred, Head, Link, router, usePage } from '@inertiajs/react';
import {
    Camera,
    Eye,
    EyeOff,
    Map as MapIcon,
    Navigation,
    Radio,
    RadioTower,
    Siren,
    Truck,
    Wrench,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { AssetsTable } from '@/components/sam/assets/assets-table';
import {
    ClearFiltersButton,
    EMPTY_PAGINATION,
    FilterDropdown,
    ListFooter,
    SearchInput,
} from '@/components/sam/list';
import { ListEmptyState, ListPage } from '@/components/sam/list-page';
import {
    PulseStat,
    PulseStrip,
    PulseStripSkeleton,
} from '@/components/sam/pulse-strip';
import { SegmentedFilter } from '@/components/sam/segmented-filter';
import { Button } from '@/components/ui/button';
import { useServerList } from '@/hooks/use-server-list';
import { TEAM_BROADCAST_EVENT_NAME } from '@/hooks/use-team-broadcasts';
import type { TeamBroadcastDetail } from '@/hooks/use-team-broadcasts';
import { ASSET_STATUS } from '@/lib/labels';
import { toneDotFor } from '@/lib/tone';
import assetRoutes from '@/routes/assets';
import type {
    AssetFilterOptions,
    AssetFilters,
    AssetRow,
    AssetsIndexProps,
    AssetsSummary,
    MonitoringSummary,
} from '@/types/assets';
import type { FleetPosition } from '@/types/realtime';

// Props each broadcast refreshes (debounced below). Feed positions
// (`fleet.positions_updated`) are applied to the rows in memory instead. A
// single position event only changes the rows: the
// fleet pulse (`summary`, several EXISTS over the snapshot tables) and the
// monitoring counts only move on status / monitoring changes.
const RELOAD_KEYS_BY_EVENT: Record<string, string[]> = {
    'asset.location_updated': ['assets'],
    'asset.status_changed': ['assets', 'pagination', 'summary', 'monitoring'],
    'asset.monitoring_changed': [
        'assets',
        'pagination',
        'summary',
        'monitoring',
    ],
};

// Location events arrive in bursts (one per asset): coalesce them over a
// wider window than the rarer status / monitoring transitions.
const RELOAD_DEBOUNCE_MS = 2000;
const LOCATION_RELOAD_DEBOUNCE_MS = 10000;

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

// Tiles of `FleetPulse`, for its skeleton while the deferred figures load.
const PULSE_LABELS = [
    'Flota',
    'Vigiladas',
    'Sin vigilar',
    'Reportando',
    'En ruta',
    'Sin señal',
    'Alerta o crítico',
    'Mantenimiento',
    'Con cámara',
] as const;

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
                            dot: toneDotFor(ASSET_STATUS, o.value),
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

export default function AssetsIndex(pageProps: AssetsIndexProps) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const [livePositions, setLivePositions] = useState<
        Map<number, FleetPosition>
    >(() => new Map());
    const assets = (pageProps.assets ?? []).map((asset) =>
        withLivePosition(asset, livePositions.get(asset.id)),
    );
    const pagination = pageProps.pagination ?? EMPTY_PAGINATION;
    const filterOptions = pageProps.filterOptions ?? EMPTY_OPTIONS;
    const summary = pageProps.summary ?? null;
    const monitoring = pageProps.monitoring ?? null;

    const [monitoringAll, setMonitoringAll] = useState(false);
    const list = useServerList({
        only: ['assets', 'pagination'],
        refreshOnly: ['assets', 'pagination', 'summary', 'monitoring'],
        filters: pageProps.filters ?? EMPTY_FILTERS,
        emptyFilters: EMPTY_FILTERS,
    });

    // "Vigilar todas": enciende cada unidad pendiente. El servidor avisa si
    // con eso se rebasa el tope (se cobra como extra, no se bloquea).
    const monitorAllPending = () => {
        if (teamSlug === null) {
            return;
        }

        setMonitoringAll(true);
        router.reload({
            only: ['assets'],
            data: { monitoring: 'pending', page: undefined },
            onSuccess: (page) => {
                const pending = (
                    (page.props.assets as AssetsIndexProps['assets']) ?? []
                ).map((asset) => asset.id);

                if (pending.length === 0) {
                    setMonitoringAll(false);

                    return;
                }

                router.put(
                    assetRoutes.monitoring.bulk.url(teamSlug),
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
                        onError: (errors) =>
                            toast.error(
                                errors.monitoring ??
                                    'No se pudieron encender las unidades.',
                            ),
                        onFinish: () => setMonitoringAll(false),
                    },
                );
            },
            onError: () => setMonitoringAll(false),
        });
    };

    // Live updates: location polls and status transitions refresh the list
    // and the pulse strip. Bursts are coalesced into a single partial reload
    // with the union of the affected props; a hidden tab waits until it is
    // visible again.
    const timer = useRef<number | null>(null);
    const timerDueAt = useRef(0);
    const pendingKeys = useRef<Set<string>>(new Set());
    const lastSummaryRefresh = useRef(0);

    useEffect(() => {
        const hidden = () => document.visibilityState === 'hidden';

        const flush = () => {
            timer.current = null;

            if (hidden() || pendingKeys.current.size === 0) {
                return;
            }

            const only = [...pendingKeys.current];
            pendingKeys.current.clear();
            router.reload({ only });
        };

        const schedule = (delay: number) => {
            const dueAt = Date.now() + delay;

            if (timer.current !== null) {
                if (timerDueAt.current <= dueAt) {
                    return;
                }

                window.clearTimeout(timer.current);
            }

            timerDueAt.current = dueAt;
            timer.current = window.setTimeout(flush, delay);
        };

        const handler = (event: Event) => {
            const detail = (event as CustomEvent<TeamBroadcastDetail>).detail;

            if (detail?.event === 'fleet.positions_updated') {
                const { positions } = detail.payload;

                setLivePositions((prev) => {
                    const next = new Map(prev);
                    positions.forEach((p) => next.set(p.asset_id, p));

                    return next;
                });

                if (
                    !hidden() &&
                    Date.now() - lastSummaryRefresh.current > SUMMARY_REFRESH_MS
                ) {
                    lastSummaryRefresh.current = Date.now();
                    router.reload({ only: ['summary', 'monitoring'] });
                }

                return;
            }

            const keys = RELOAD_KEYS_BY_EVENT[detail?.event ?? ''];

            if (!keys) {
                return;
            }

            keys.forEach((key) => pendingKeys.current.add(key));
            schedule(
                detail?.event === 'asset.location_updated'
                    ? LOCATION_RELOAD_DEBOUNCE_MS
                    : RELOAD_DEBOUNCE_MS,
            );
        };

        const onVisibilityChange = () => {
            if (!hidden() && pendingKeys.current.size > 0) {
                schedule(RELOAD_DEBOUNCE_MS);
            }
        };

        window.addEventListener(TEAM_BROADCAST_EVENT_NAME, handler);
        document.addEventListener('visibilitychange', onVisibilityChange);

        return () => {
            window.removeEventListener(TEAM_BROADCAST_EVENT_NAME, handler);
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );

            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        };
    }, []);

    const handleSelect = (id: number) => {
        if (teamSlug !== null) {
            router.visit(assetRoutes.show([teamSlug, id]));
        }
    };

    const total = summary?.total ?? pagination.total;

    return (
        <>
            <Head title="Flota" />
            <ListPage
                title="Flota"
                meta={
                    <span className="text-xs text-fg-3">
                        <span className="font-medium text-fg-1">{total}</span>{' '}
                        {total === 1 ? 'unidad' : 'unidades'}
                        {summary && (
                            <>
                                {' · '}
                                <span className="text-severity-low">
                                    {summary.reporting} reportando ahora
                                </span>
                            </>
                        )}
                    </span>
                }
                actions={
                    teamSlug && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={assetRoutes.map(teamSlug)}>
                                <MapIcon size={13} />
                                Mapa en vivo
                            </Link>
                        </Button>
                    )
                }
                onRefresh={list.refresh}
                refreshing={list.refreshing}
                pulse={
                    <>
                        {monitoring && (
                            <PendingBanner
                                monitoring={monitoring}
                                teamSlug={teamSlug}
                                busy={monitoringAll}
                                onShowPending={() =>
                                    list.setFilter('monitoring', 'pending')
                                }
                                onMonitorAll={monitorAllPending}
                            />
                        )}
                        <Deferred
                            data={['summary', 'monitoring']}
                            fallback={
                                <PulseStripSkeleton labels={PULSE_LABELS} />
                            }
                        >
                            {summary && (
                                <FleetPulse
                                    summary={summary}
                                    monitoring={monitoring}
                                    status={list.filters.status}
                                    monitoringFilter={list.filters.monitoring}
                                    onStatus={(status) =>
                                        list.setFilter('status', status)
                                    }
                                    onMonitoring={(value) =>
                                        list.setFilter('monitoring', value)
                                    }
                                />
                            )}
                        </Deferred>
                    </>
                }
                filters={
                    <FilterBar
                        filters={list.filters}
                        options={filterOptions}
                        summary={summary}
                        onApply={list.apply}
                    />
                }
                footer={
                    <ListFooter
                        pagination={pagination}
                        shown={assets.length}
                        onPage={list.goToPage}
                        noun={['unidad', 'unidades']}
                    />
                }
            >
                <AssetsTable
                    rows={assets}
                    onSelect={handleSelect}
                    teamSlug={teamSlug}
                    empty={
                        <ListEmptyState
                            icon={Truck}
                            filtered={list.hasActiveFilters}
                            title="Sin unidades"
                            description="Cuando la sincronización de integraciones registre vehículos aparecerán aquí."
                            filteredDescription="Ninguna unidad coincide con los filtros aplicados."
                        />
                    }
                />
            </ListPage>
        </>
    );
}

AssetsIndex.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'Flota',
            href: props.currentTeam
                ? assetRoutes.index.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
