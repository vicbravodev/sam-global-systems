import { Head, router, usePage } from '@inertiajs/react';
import {
    Clock,
    Moon,
    ShieldAlert,
    Truck,
    UserCheck,
    UserX,
    Users,
} from 'lucide-react';
import { DriversTable } from '@/components/sam/drivers/drivers-table';
import {
    ClearFiltersButton,
    EMPTY_PAGINATION,
    FilterDropdown,
    ListFooter,
    SearchInput,
} from '@/components/sam/list';
import { ListEmptyState, ListPage } from '@/components/sam/list-page';
import { PulseStat, PulseStrip } from '@/components/sam/pulse-strip';
import { SegmentedFilter } from '@/components/sam/segmented-filter';
import { useServerList } from '@/hooks/use-server-list';
import driverRoutes from '@/routes/drivers';
import type {
    DriverFilterOptions,
    DriverFilters,
    DriversIndexProps,
    DriversSummary,
} from '@/types/drivers';

const STATUS_DOT: Record<string, string> = {
    active: 'bg-severity-low',
    off_duty: 'bg-fg-3',
    unavailable: 'bg-fg-3',
    suspended: 'bg-severity-critical',
    under_review: 'bg-severity-medium',
};

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

// ---- Main page ----

const EMPTY_FILTERS: DriverFilters = { q: null, status: null };

const EMPTY_OPTIONS: DriverFilterOptions = { statuses: [] };

export default function DriversIndex() {
    const page = usePage();
    const pageProps = page.props as unknown as DriversIndexProps;
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const drivers = pageProps.drivers ?? [];
    const pagination = pageProps.pagination ?? EMPTY_PAGINATION;
    const filterOptions = pageProps.filterOptions ?? EMPTY_OPTIONS;
    const summary = pageProps.summary ?? null;

    const list = useServerList({
        only: ['drivers', 'pagination'],
        refreshOnly: ['drivers', 'pagination', 'summary'],
        filters: pageProps.filters ?? EMPTY_FILTERS,
        emptyFilters: EMPTY_FILTERS,
    });

    const handleSelect = (id: number) => {
        if (teamSlug !== null) {
            router.visit(driverRoutes.show([teamSlug, id]));
        }
    };

    const total = summary?.total ?? pagination.total;

    return (
        <>
            <Head title="Conductores" />
            <ListPage
                title="Conductores"
                meta={
                    <span className="text-xs text-fg-3">
                        <span className="font-medium text-fg-1">{total}</span>{' '}
                        {total === 1 ? 'conductor' : 'conductores'}
                        {summary && (
                            <>
                                {' · '}
                                <span className="text-severity-low">
                                    {summary.statuses.active} en servicio
                                </span>
                            </>
                        )}
                    </span>
                }
                onRefresh={list.refresh}
                refreshing={list.refreshing}
                pulse={
                    summary && (
                        <RosterPulse
                            summary={summary}
                            status={list.filters.status}
                            onStatus={(status) =>
                                list.setFilter('status', status)
                            }
                        />
                    )
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
                        shown={drivers.length}
                        onPage={list.goToPage}
                        noun={['conductor', 'conductores']}
                    />
                }
            >
                <DriversTable
                    rows={drivers}
                    onSelect={handleSelect}
                    empty={
                        <ListEmptyState
                            icon={Users}
                            filtered={list.hasActiveFilters}
                            title="Sin conductores"
                            description="Cuando la sincronización de integraciones registre conductores aparecerán aquí."
                            filteredDescription="Ningún conductor coincide con los filtros aplicados."
                        />
                    }
                    presence={pageProps.columns}
                />
            </ListPage>
        </>
    );
}

DriversIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Conductores',
            href: props.currentTeam
                ? driverRoutes.index.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
