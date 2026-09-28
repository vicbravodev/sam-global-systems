import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Camera,
    Map as MapIcon,
    Navigation,
    Radio,
    RadioTower,
    RefreshCw,
    Siren,
    Truck,
    Wrench,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
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
    AssetsIndexProps,
    AssetsPagination,
    AssetsSummary,
} from '@/types/assets';

// Broadcast events that refresh the fleet list. Location polls can arrive in
// bursts (one event per asset), so reloads are debounced below.
const RELOAD_EVENTS = new Set([
    'asset.location_updated',
    'asset.status_changed',
]);

const RELOAD_DEBOUNCE_MS = 2000;

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

// ---- Pulse strip ----

function FleetPulse({
    summary,
    status,
    onStatus,
}: {
    summary: AssetsSummary;
    status: string | null;
    onStatus: (value: string | null) => void;
}) {
    const toggle = (value: string) => () =>
        onStatus(status === value ? null : value);

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
        filters.q !== null || filters.status !== null || filters.type !== null;

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

            {hasActive && (
                <ClearFiltersButton
                    onClick={() =>
                        onApply({ q: null, status: null, type: null })
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

const EMPTY_FILTERS: AssetFilters = { q: null, status: null, type: null };

const EMPTY_OPTIONS: AssetFilterOptions = { statuses: [], types: [] };

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
    const assets = pageProps.assets ?? [];
    const pagination = pageProps.pagination ?? EMPTY_PAGINATION;
    const serverFilters = pageProps.filters ?? EMPTY_FILTERS;
    const filterOptions = pageProps.filterOptions ?? EMPTY_OPTIONS;
    const summary = pageProps.summary ?? null;

    const [refreshing, setRefreshing] = useState(false);
    const [filters, setFilters] = useState<AssetFilters>(serverFilters);

    // Re-sync local filter state if the server echoes a different set
    // (e.g. after a browser back/forward navigation).
    useEffect(() => {
        setFilters(serverFilters);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [serverFilters.q, serverFilters.status, serverFilters.type]);

    const refresh = () => {
        setRefreshing(true);
        router.reload({
            only: ['assets', 'pagination', 'summary'],
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
                // Changing filters always restarts at the first page.
                page: undefined,
            },
        });
    }, []);

    const goToPage = useCallback((target: number) => {
        router.reload({
            only: ['assets', 'pagination'],
            data: { page: target },
        });
    }, []);

    // Live updates: location polls and status transitions refresh the list
    // and the pulse strip. Bursts are coalesced into a single partial reload.
    const timer = useRef<number | null>(null);

    useEffect(() => {
        const handler = (event: Event) => {
            const detail = (event as CustomEvent<TeamBroadcastDetail>).detail;

            if (!RELOAD_EVENTS.has(detail?.event ?? '')) {
                return;
            }

            if (timer.current !== null) {
                return;
            }

            timer.current = window.setTimeout(() => {
                timer.current = null;
                router.reload({ only: ['assets', 'pagination', 'summary'] });
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
        serverFilters.type !== null;

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

                {summary && (
                    <FleetPulse
                        summary={summary}
                        status={filters.status}
                        onStatus={(status) =>
                            applyFilters({ ...filters, status })
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
