import { Head, router, usePage } from '@inertiajs/react';
import {
    Clock,
    Moon,
    RefreshCw,
    ShieldAlert,
    Truck,
    UserCheck,
    UserX,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { DriversTable } from '@/components/sam/drivers/drivers-table';
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
import { cn } from '@/lib/utils';
import type {
    DriverFilterOptions,
    DriverFilters,
    DriversIndexProps,
    DriversPagination,
    DriversSummary,
} from '@/types/drivers';

const STATUS_DOT: Record<string, string> = {
    active: 'bg-severity-low',
    off_duty: 'bg-fg-3',
    unavailable: 'bg-fg-3',
    suspended: 'bg-severity-critical',
    under_review: 'bg-severity-medium',
};

// ---- PageHead ----

function PageHead({
    total,
    activeNow,
    onRefresh,
    refreshing,
}: {
    total: number;
    activeNow: number | null;
    onRefresh: () => void;
    refreshing: boolean;
}) {
    return (
        <PageHeader
            title="Conductores"
            meta={
                <span className="text-xs text-fg-3">
                    <span className="font-medium text-fg-1">{total}</span>{' '}
                    {total === 1 ? 'conductor' : 'conductores'}
                    {activeNow !== null && (
                        <>
                            {' · '}
                            <span className="text-severity-low">
                                {activeNow} en servicio
                            </span>
                        </>
                    )}
                </span>
            }
            actions={
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
            }
            className="shrink-0 border-b border-border bg-surface-1 px-5 py-3"
        />
    );
}

// ---- Pulse strip ----

function RosterPulse({
    summary,
    status,
    onStatus,
}: {
    summary: DriversSummary;
    status: string | null;
    onStatus: (value: string | null) => void;
}) {
    const toggle = (value: string) => () =>
        onStatus(status === value ? null : value);
    const attention =
        summary.statuses.under_review + summary.statuses.suspended;

    return (
        <PulseStrip>
            <PulseStat
                label="Roster"
                value={summary.total}
                icon={Users}
                hint="conductores registrados"
                onClick={() => onStatus(null)}
                active={status === null}
            />
            <PulseStat
                label="Activos"
                value={summary.statuses.active}
                icon={UserCheck}
                tone="ok"
                hint="en servicio"
                onClick={toggle('active')}
                active={status === 'active'}
            />
            <PulseStat
                label="Fuera de turno"
                value={summary.statuses.off_duty}
                icon={Moon}
                hint="descansando"
                onClick={toggle('off_duty')}
                active={status === 'off_duty'}
            />
            <PulseStat
                label="Atención"
                value={attention}
                icon={UserX}
                tone={attention > 0 ? 'warn' : 'neutral'}
                hint={`${summary.statuses.under_review} en revisión · ${summary.statuses.suspended} suspendidos`}
                onClick={toggle('under_review')}
                active={status === 'under_review'}
            />
            <PulseStat
                label="Riesgo alto"
                value={summary.highRisk}
                icon={ShieldAlert}
                tone={summary.highRisk > 0 ? 'critical' : 'neutral'}
                hint="perfil alto o crítico"
            />
            <PulseStat
                label="Sin unidad"
                value={summary.unassigned}
                icon={Truck}
                tone={summary.unassigned > 0 ? 'warn' : 'neutral'}
                hint="sin vehículo asignado"
            />
            <PulseStat
                label="Vistos 24 h"
                value={summary.seenToday}
                icon={Clock}
                tone="info"
                live={summary.seenToday > 0}
                hint="con señal del proveedor"
            />
        </PulseStrip>
    );
}

// ---- FilterBar ----

interface FilterBarProps {
    filters: DriverFilters;
    options: DriverFilterOptions;
    summary: DriversSummary | null;
    onApply: (next: DriverFilters) => void;
}

function FilterBar({ filters, options, summary, onApply }: FilterBarProps) {
    const hasActive = filters.q !== null || filters.status !== null;

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
                    allCount={summary.total}
                    options={options.statuses.map((o) => ({
                        value: o.value,
                        label: o.label,
                        count: summary.statuses[
                            o.value as keyof DriversSummary['statuses']
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

            {hasActive && (
                <ClearFiltersButton
                    onClick={() => onApply({ q: null, status: null })}
                />
            )}
        </div>
    );
}

// ---- Empty state ----

function RosterEmptyState({ filtered }: { filtered: boolean }) {
    return (
        <EmptyState
            className="min-h-0 flex-1"
            icon={Users}
            title={filtered ? 'Sin resultados' : 'Sin conductores'}
            description={
                filtered
                    ? 'Ningún conductor coincide con los filtros aplicados.'
                    : 'Cuando la sincronización de integraciones registre conductores aparecerán aquí.'
            }
        />
    );
}

// ---- Main page ----

const EMPTY_FILTERS: DriverFilters = { q: null, status: null };

const EMPTY_OPTIONS: DriverFilterOptions = { statuses: [] };

const EMPTY_PAGINATION: DriversPagination = {
    page: 1,
    perPage: 50,
    total: 0,
    lastPage: 1,
};

export default function DriversIndex() {
    const page = usePage();
    const pageProps = page.props as unknown as DriversIndexProps;
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const drivers = pageProps.drivers ?? [];
    const pagination = pageProps.pagination ?? EMPTY_PAGINATION;
    const serverFilters = pageProps.filters ?? EMPTY_FILTERS;
    const filterOptions = pageProps.filterOptions ?? EMPTY_OPTIONS;
    const summary = pageProps.summary ?? null;

    const [refreshing, setRefreshing] = useState(false);
    const [filters, setFilters] = useState<DriverFilters>(serverFilters);

    // Re-sync local filter state if the server echoes a different set
    // (e.g. after a browser back/forward navigation).
    useEffect(() => {
        setFilters(serverFilters);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [serverFilters.q, serverFilters.status]);

    const refresh = () => {
        setRefreshing(true);
        router.reload({
            only: ['drivers', 'pagination', 'summary'],
            onFinish: () => setRefreshing(false),
        });
    };

    const applyFilters = useCallback((next: DriverFilters) => {
        setFilters(next);
        router.reload({
            only: ['drivers', 'pagination', 'filters'],
            data: {
                q: next.q ?? undefined,
                status: next.status ?? undefined,
                // Changing filters always restarts at the first page.
                page: undefined,
            },
        });
    }, []);

    const goToPage = useCallback((target: number) => {
        router.reload({
            only: ['drivers', 'pagination'],
            data: { page: target },
        });
    }, []);

    const handleSelect = useCallback(
        (id: number) => {
            if (teamSlug !== null) {
                router.visit(`/${teamSlug}/drivers/${id}`);
            }
        },
        [teamSlug],
    );

    const hasActiveFilters =
        serverFilters.q !== null || serverFilters.status !== null;

    return (
        <>
            <Head title="Conductores" />
            <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
                <PageHead
                    total={summary?.total ?? pagination.total}
                    activeNow={summary?.statuses.active ?? null}
                    onRefresh={refresh}
                    refreshing={refreshing}
                />

                {summary && (
                    <RosterPulse
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

                <DriversTable
                    rows={drivers}
                    onSelect={handleSelect}
                    empty={<RosterEmptyState filtered={hasActiveFilters} />}
                    presence={pageProps.columns}
                />

                <ListFooter
                    pagination={pagination}
                    shown={drivers.length}
                    onPage={goToPage}
                    noun={['conductor', 'conductores']}
                />
            </div>
        </>
    );
}

DriversIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Conductores',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/drivers`
                : '/drivers',
        },
    ],
});
