import type { SharedPageProps } from '@inertiajs/core';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowUpRight, RefreshCw, Search, User, X } from 'lucide-react';
import {
    lazy,
    Suspense,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { DataFreshness } from '@/components/sam/assets/data-freshness';
import { MapLoading } from '@/components/sam/map/map-controls';
import {
    isMoving,
    speedLine,
    statusColor,
    STATUS_URGENCY,
} from '@/components/sam/map/markers';
import { RealtimeStatus } from '@/components/sam/realtime-status';
import type { RealtimeState } from '@/components/sam/realtime-status';
import { Button } from '@/components/ui/button';
import { useRealtimeConnection } from '@/hooks/use-realtime-connection';
import { useTeamBroadcast } from '@/hooks/use-team-broadcasts';
import { formatDateTime } from '@/lib/format';
import { relativeLabel } from '@/lib/time';
import { cn } from '@/lib/utils';
import assetRoutes from '@/routes/assets';
import type {
    AssetMarker,
    AssetsMapProps,
    AssetStatusValue,
} from '@/types/assets';

// maplibre-gl loads in its own chunk: the header and the roster paint first,
// the map area shows its loading frame meanwhile.
const LiveMap = lazy(() =>
    import('@/components/sam/assets/live-map').then((module) => ({
        default: module.LiveMap,
    })),
);

// Reload (to pick up brand-new positioned assets) at most this often.
const RELOAD_DEBOUNCE_MS = 5000;

const STATUS_ORDER: AssetStatusValue[] = [
    'critical',
    'alert',
    'maintenance',
    'active',
    'inactive',
    'offline',
];

function connectionToStatus(
    state: ReturnType<typeof useRealtimeConnection>,
): RealtimeState {
    switch (state) {
        case 'connected':
            return 'ok';
        case 'connecting':
        case 'reconnecting':
            return 'warn';
        default:
            return 'down';
    }
}

function StatusDot({
    status,
    className,
}: {
    status: AssetStatusValue;
    className?: string;
}) {
    const muted = status === 'offline' || status === 'inactive';

    return (
        <span
            aria-hidden="true"
            className={cn('size-2 shrink-0 rounded-full', className)}
            style={
                muted
                    ? { boxShadow: `inset 0 0 0 1.5px ${statusColor(status)}` }
                    : { backgroundColor: statusColor(status) }
            }
        />
    );
}

/** Most urgent first, then moving before parked, then by code. */
function compareUnits(a: AssetMarker, b: AssetMarker): number {
    return (
        STATUS_URGENCY[b.status] - STATUS_URGENCY[a.status] ||
        Number(isMoving(b)) - Number(isMoving(a)) ||
        (a.code ?? a.name).localeCompare(b.code ?? b.name, 'es', {
            numeric: true,
        })
    );
}

function UnitCallout({
    asset,
    statusLabels,
    teamSlug,
    onClose,
}: {
    asset: AssetMarker;
    statusLabels: Record<string, string>;
    teamSlug: string | null;
    onClose: () => void;
}) {
    return (
        <div className="overflow-hidden rounded-lg border border-border bg-surface-1 shadow-lg">
            <div className="flex items-start gap-2 px-3 pt-3 pb-2">
                <StatusDot status={asset.status} className="mt-1.5" />
                <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-semibold text-fg-1">
                        {asset.name}
                    </div>
                    <div className="text-2xs text-fg-3">
                        {asset.code ? `${asset.code} · ` : ''}
                        {statusLabels[asset.status] ?? asset.status}
                    </div>
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Cerrar"
                    className="-m-1 grid size-6 cursor-pointer place-items-center rounded-sm text-fg-3 transition-colors hover:bg-surface-2 hover:text-fg-1 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <X className="size-3.5" />
                </button>
            </div>

            <dl className="grid grid-cols-2 gap-x-3 gap-y-2 border-t border-border px-3 py-2.5 text-xs">
                <div>
                    <dt className="text-2xs text-fg-3">Movimiento</dt>
                    <dd className="font-medium text-fg-1 tabular-nums">
                        {speedLine(asset)}
                    </dd>
                </div>
                <div>
                    <dt className="text-2xs text-fg-3">Última posición</dt>
                    <dd
                        className="font-medium text-fg-1"
                        title={formatDateTime(asset.recordedAt)}
                    >
                        {relativeLabel(asset.recordedAt)}
                    </dd>
                </div>
                <div className="col-span-2">
                    <dt className="text-2xs text-fg-3">Conductor</dt>
                    <dd className="flex items-center gap-1 font-medium text-fg-1">
                        <User className="size-3 text-fg-3" aria-hidden="true" />
                        {asset.driver ?? (
                            <span className="font-normal text-fg-3">
                                Sin asignar
                            </span>
                        )}
                    </dd>
                </div>
            </dl>

            <div className="flex items-center justify-between gap-2 border-t border-border bg-surface-2/60 px-3 py-2">
                <span className="font-mono text-2xs text-fg-3 tabular-nums">
                    {asset.latitude.toFixed(4)}, {asset.longitude.toFixed(4)}
                </span>
                {teamSlug && (
                    <Button size="sm" asChild>
                        <Link
                            href={assetRoutes.show([teamSlug, asset.id])}
                            prefetch
                        >
                            Ver unidad
                            <ArrowUpRight className="size-3.5" />
                        </Link>
                    </Button>
                )}
            </div>
        </div>
    );
}

export default function AssetsMap(pageProps: AssetsMapProps) {
    const page = usePage();
    const serverMarkers = useMemo(
        () => pageProps.assets ?? [],
        [pageProps.assets],
    );
    const unpositionedCount = pageProps.unpositionedCount ?? 0;
    const statusLabels = useMemo(
        () => pageProps.statusLabels ?? {},
        [pageProps.statusLabels],
    );
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const connection = useRealtimeConnection();

    const [markers, setMarkers] = useState<AssetMarker[]>(serverMarkers);
    const [refreshing, setRefreshing] = useState(false);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [focusRequest, setFocusRequest] = useState(0);
    const [query, setQuery] = useState('');
    const [hidden, setHidden] = useState<ReadonlySet<AssetStatusValue>>(
        () => new Set(),
    );

    // Re-sync in-memory markers whenever the server prop refreshes.
    useEffect(() => {
        setMarkers(serverMarkers);
    }, [serverMarkers]);

    const newestAt = useMemo(
        () =>
            markers.reduce<string | null>(
                (newest, m) =>
                    newest === null ||
                    Date.parse(m.recordedAt) > Date.parse(newest)
                        ? m.recordedAt
                        : newest,
                null,
            ),
        [markers],
    );

    const counts = useMemo(() => {
        const byStatus = new Map<AssetStatusValue, number>();
        markers.forEach((m) =>
            byStatus.set(m.status, (byStatus.get(m.status) ?? 0) + 1),
        );

        return byStatus;
    }, [markers]);

    const moving = useMemo(() => markers.filter(isMoving).length, [markers]);

    const visible = useMemo(() => {
        const q = query.trim().toLocaleLowerCase('es');

        return markers
            .filter((m) => !hidden.has(m.status))
            .filter(
                (m) =>
                    q === '' ||
                    m.name.toLocaleLowerCase('es').includes(q) ||
                    (m.code ?? '').toLocaleLowerCase('es').includes(q) ||
                    (m.driver ?? '').toLocaleLowerCase('es').includes(q),
            );
    }, [markers, hidden, query]);

    const listed = useMemo(() => [...visible].sort(compareUnits), [visible]);

    // A unit filtered out of view cannot stay selected.
    useEffect(() => {
        if (selectedId !== null && !visible.some((m) => m.id === selectedId)) {
            setSelectedId(null);
        }
    }, [visible, selectedId]);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setSelectedId(null);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const refresh = () => {
        setRefreshing(true);
        router.reload({
            only: ['assets', 'unpositionedCount'],
            onFinish: () => setRefreshing(false),
        });
    };

    // Live updates move markers IN MEMORY (no server roundtrip). A debounced
    // partial reload only fires when an unknown unit shows up (it just got
    // its first position).
    const reloadTimer = useRef<number | null>(null);
    const reloadRequestedFor = useRef<Set<number>>(new Set());

    const scheduleReload = useCallback(() => {
        if (reloadTimer.current !== null) {
            return;
        }

        reloadTimer.current = window.setTimeout(() => {
            reloadTimer.current = null;
            router.reload({ only: ['assets', 'unpositionedCount'] });
        }, RELOAD_DEBOUNCE_MS);
    }, []);

    useEffect(
        () => () => {
            if (reloadTimer.current !== null) {
                window.clearTimeout(reloadTimer.current);
            }
        },
        [],
    );

    useTeamBroadcast(
        [
            'fleet.positions_updated',
            'asset.location_updated',
            'asset.status_changed',
        ],
        (detail) => {
            if (detail.event === 'fleet.positions_updated') {
                // One batch per feed cycle (~5 s) for the whole fleet.
                const byId = new Map(
                    (
                        detail.payload as {
                            positions: {
                                asset_id: number;
                                latitude: number;
                                longitude: number;
                                speed_kph: number | null;
                                heading: number | null;
                                recorded_at: string;
                                moving: boolean | null;
                            }[];
                        }
                    ).positions.map((p) => [p.asset_id, p]),
                );

                setMarkers((prev) => {
                    const onMap = new Set<number>();

                    const next = prev.map((m) => {
                        const p = byId.get(m.id);

                        if (p === undefined) {
                            return m;
                        }

                        onMap.add(m.id);

                        return Date.parse(p.recorded_at) >=
                            Date.parse(m.recordedAt)
                            ? {
                                  ...m,
                                  latitude: p.latitude,
                                  longitude: p.longitude,
                                  speed: p.speed_kph,
                                  heading: p.heading,
                                  moving: p.moving,
                                  recordedAt: p.recorded_at,
                              }
                            : m;
                    });

                    // An asset reporting its first position is not on the map
                    // yet: reload once for it, not on every feed tick (an
                    // asset the map never lists would otherwise reload it
                    // forever).
                    const firstSeen = [...byId.keys()].filter(
                        (id) =>
                            !onMap.has(id) &&
                            !reloadRequestedFor.current.has(id),
                    );

                    if (firstSeen.length > 0) {
                        firstSeen.forEach((id) =>
                            reloadRequestedFor.current.add(id),
                        );
                        scheduleReload();
                    }

                    return next;
                });

                return;
            }

            if (detail.event === 'asset.location_updated') {
                const payload = detail.payload as {
                    asset_id: number;
                    latitude: number;
                    longitude: number;
                    recorded_at: string;
                };

                setMarkers((prev) => {
                    if (!prev.some((m) => m.id === payload.asset_id)) {
                        scheduleReload();

                        return prev;
                    }

                    return prev.map((m) =>
                        m.id === payload.asset_id &&
                        Date.parse(payload.recorded_at) >=
                            Date.parse(m.recordedAt)
                            ? {
                                  ...m,
                                  latitude: payload.latitude,
                                  longitude: payload.longitude,
                                  recordedAt: payload.recorded_at,
                              }
                            : m,
                    );
                });

                return;
            }

            const payload = detail.payload as {
                asset_id: number;
                new_status: string;
            };

            setMarkers((prev) =>
                prev.map((m) =>
                    m.id === payload.asset_id
                        ? {
                              ...m,
                              status: payload.new_status as AssetStatusValue,
                          }
                        : m,
                ),
            );
        },
    );

    const pickFromList = (id: number) => {
        setSelectedId(id);
        setFocusRequest((n) => n + 1);
    };

    const toggleStatus = (status: AssetStatusValue) => {
        setHidden((current) => {
            const next = new Set(current);

            if (next.has(status)) {
                next.delete(status);
            } else {
                next.add(status);
            }

            return next;
        });
    };

    const presentStatuses = STATUS_ORDER.filter((s) => counts.has(s));
    const filtering = hidden.size > 0 || query.trim() !== '';

    const statusChips = (
        <div className="flex flex-wrap gap-1.5">
            {presentStatuses.map((status) => {
                const off = hidden.has(status);

                return (
                    <button
                        key={status}
                        type="button"
                        onClick={() => toggleStatus(status)}
                        aria-pressed={!off}
                        className={cn(
                            'inline-flex h-7 cursor-pointer items-center gap-1.5 rounded-full border px-2.5 text-2xs font-medium transition-colors duration-(--motion-fast) focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            off
                                ? 'border-dashed border-border bg-transparent text-fg-3 hover:text-fg-2'
                                : 'border-border bg-surface-1 text-fg-1 hover:bg-surface-2',
                        )}
                    >
                        <StatusDot
                            status={status}
                            className={cn(off && 'opacity-40')}
                        />
                        {statusLabels[status] ?? status}
                        <span className="text-fg-3 tabular-nums">
                            {counts.get(status)}
                        </span>
                    </button>
                );
            })}
        </div>
    );

    return (
        <>
            <Head title="Mapa en vivo" />
            <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
                <header className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-border bg-surface-1 px-5 py-3">
                    <div className="flex items-baseline gap-3">
                        <h1 className="text-md font-semibold text-fg-1">
                            Mapa en vivo
                        </h1>
                        <span className="text-xs text-fg-3 tabular-nums">
                            <span className="font-medium text-fg-1">
                                {markers.length}
                            </span>{' '}
                            {markers.length === 1 ? 'unidad' : 'unidades'}
                            {' · '}
                            <span className="font-medium text-fg-1">
                                {moving}
                            </span>{' '}
                            en movimiento
                            {unpositionedCount > 0 &&
                                ` · ${unpositionedCount} sin posición`}
                        </span>
                    </div>
                    <div className="flex items-center gap-3">
                        <DataFreshness newestAt={newestAt} />
                        <RealtimeStatus
                            state={connectionToStatus(connection)}
                        />
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={refresh}
                            disabled={refreshing}
                        >
                            <RefreshCw
                                size={13}
                                className={cn(refreshing && 'animate-spin')}
                            />
                            Refrescar
                        </Button>
                    </div>
                </header>

                <div className="flex min-h-0 flex-1">
                    {/* Unit roster: search, status filter, most urgent first. */}
                    <aside className="hidden w-80 shrink-0 flex-col border-r border-border bg-surface-1 lg:flex">
                        <div className="flex flex-col gap-2.5 border-b border-border p-3">
                            <label className="relative block">
                                <span className="sr-only">Buscar unidad</span>
                                <Search
                                    className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-fg-3"
                                    aria-hidden="true"
                                />
                                <input
                                    type="search"
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    placeholder="Unidad, código o conductor"
                                    className="h-8 w-full rounded-md border border-border bg-background pr-2.5 pl-8 text-xs text-fg-1 transition-colors outline-none placeholder:text-fg-3 hover:border-border-strong focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                                />
                            </label>
                            {statusChips}
                        </div>

                        <ul
                            className="min-h-0 flex-1 overflow-y-auto py-1"
                            aria-label="Unidades en el mapa"
                        >
                            {listed.map((asset) => {
                                const isSelected = asset.id === selectedId;

                                return (
                                    <li key={asset.id}>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                pickFromList(asset.id)
                                            }
                                            aria-current={
                                                isSelected ? 'true' : undefined
                                            }
                                            className={cn(
                                                'flex h-(--row-relaxed) w-full cursor-pointer items-center gap-2.5 px-3 text-left transition-colors duration-(--motion-fast) outline-none focus-visible:bg-surface-2',
                                                isSelected
                                                    ? 'bg-surface-2'
                                                    : 'hover:bg-surface-2/70',
                                            )}
                                        >
                                            <StatusDot status={asset.status} />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-xs font-medium text-fg-1">
                                                    {asset.code
                                                        ? `${asset.code} · `
                                                        : ''}
                                                    {asset.name}
                                                </span>
                                                <span className="block truncate text-2xs text-fg-3">
                                                    {asset.driver ??
                                                        (statusLabels[
                                                            asset.status
                                                        ] ||
                                                            asset.status)}
                                                </span>
                                            </span>
                                            <span className="flex shrink-0 flex-col items-end gap-0.5">
                                                <span
                                                    className={cn(
                                                        'text-2xs tabular-nums',
                                                        isMoving(asset)
                                                            ? 'font-medium text-fg-1'
                                                            : 'text-fg-3',
                                                    )}
                                                >
                                                    {isMoving(asset) &&
                                                    asset.speed !== null
                                                        ? `${Math.round(asset.speed)} km/h`
                                                        : isMoving(asset)
                                                          ? 'En ruta'
                                                          : 'Detenido'}
                                                </span>
                                                <span className="text-3xs text-fg-3">
                                                    {relativeLabel(
                                                        asset.recordedAt,
                                                    )}
                                                </span>
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>

                        {listed.length === 0 && markers.length > 0 && (
                            <div className="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center">
                                <p className="text-xs text-fg-3">
                                    Ninguna unidad coincide con los filtros.
                                </p>
                                {filtering && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setQuery('');
                                            setHidden(new Set());
                                        }}
                                    >
                                        Quitar filtros
                                    </Button>
                                )}
                            </div>
                        )}
                    </aside>

                    <div className="relative min-h-0 min-w-0 flex-1">
                        <Suspense
                            fallback={
                                <div className="relative h-full w-full overflow-hidden bg-surface-2">
                                    <MapLoading />
                                </div>
                            }
                        >
                            <LiveMap
                                markers={visible}
                                statusLabels={statusLabels}
                                selectedId={selectedId}
                                onSelect={setSelectedId}
                                focusRequest={focusRequest}
                                renderCallout={(asset) => (
                                    <UnitCallout
                                        asset={asset}
                                        statusLabels={statusLabels}
                                        teamSlug={teamSlug}
                                        onClose={() => setSelectedId(null)}
                                    />
                                )}
                            />
                        </Suspense>

                        {/* Small screens: the roster is hidden, the status
                            filter floats on the map. */}
                        {presentStatuses.length > 0 && (
                            <div className="pointer-events-auto absolute top-3 left-3 z-10 max-w-[calc(100%-4.5rem)] lg:hidden">
                                {statusChips}
                            </div>
                        )}

                        {markers.length === 0 && (
                            <div className="pointer-events-none absolute inset-x-0 bottom-6 z-10 flex justify-center px-4">
                                <span className="max-w-md rounded-md border border-border bg-surface-1 px-3 py-2 text-center text-xs text-fg-2 shadow-sm">
                                    Sin unidades posicionadas todavía.
                                    Aparecerán aquí en cuanto la integración
                                    registre su primera ubicación.
                                </span>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

AssetsMap.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'Mapa en vivo',
            href: props.currentTeam
                ? assetRoutes.map.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
