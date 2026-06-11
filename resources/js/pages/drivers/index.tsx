import { Head, router, usePage } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Filter,
    RefreshCw,
    Search,
    Users,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { DriversTable } from '@/components/sam/drivers/drivers-table';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { cn } from '@/lib/utils';
import type {
    DriverFilterOptions,
    DriverFilters,
    DriversIndexProps,
    DriversPagination,
} from '@/types/drivers';

// ---- PageHead ----

function PageHead({
    total,
    onRefresh,
    refreshing,
}: {
    total: number;
    onRefresh: () => void;
    refreshing: boolean;
}) {
    return (
        <PageHeader
            title="Conductores"
            meta={
                <span className="text-[12px] text-fg-3">
                    <span className="font-medium text-fg-1">{total}</span>{' '}
                    {total === 1 ? 'conductor' : 'conductores'}
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
    filters: DriverFilters;
    options: DriverFilterOptions;
    onApply: (next: DriverFilters) => void;
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

    const hasActive = filters.q !== null || filters.status !== null;

    return (
        <div className="flex shrink-0 items-center gap-2 border-b border-border bg-background px-5 py-2">
            <div className="mr-1 flex items-center gap-1.5 rounded-md border border-border bg-surface-1 px-2.5 py-1.5 text-[12px] text-fg-3">
                <Search size={12} />
                <input
                    type="text"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Buscar por nombre o código…"
                    className="w-48 border-none bg-transparent text-[12px] text-fg-1 outline-none placeholder:text-fg-3"
                />
            </div>

            <FilterDropdown
                label="Estado"
                value={filters.status}
                options={options.statuses}
                onChange={(v) => onApply({ ...filters, status: v })}
            />

            {hasActive && (
                <button
                    type="button"
                    onClick={() => onApply({ q: null, status: null })}
                    className="flex items-center gap-1 rounded-sm border border-dashed border-border px-2.5 py-1.5 text-[11px] text-fg-3 transition-colors hover:border-border-strong"
                >
                    <X size={11} />
                    Limpiar
                </button>
            )}
        </div>
    );
}

// ---- Footer / pagination ----

function RosterFooter({
    pagination,
    shown,
    onPage,
}: {
    pagination: DriversPagination;
    shown: number;
    onPage: (page: number) => void;
}) {
    const from =
        shown === 0 ? 0 : (pagination.page - 1) * pagination.perPage + 1;
    const to = (pagination.page - 1) * pagination.perPage + shown;

    return (
        <div className="flex shrink-0 items-center justify-between border-t border-border bg-surface-1 px-5 py-2">
            <span className="text-[11px] text-fg-3">
                {from}–{to} de {pagination.total}{' '}
                {pagination.total === 1 ? 'conductor' : 'conductores'}
            </span>
            <div className="flex items-center gap-1">
                <Button
                    variant="ghost"
                    size="sm"
                    disabled={pagination.page <= 1}
                    onClick={() => onPage(pagination.page - 1)}
                >
                    <ChevronLeft size={13} />
                    Anterior
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    disabled={pagination.page >= pagination.lastPage}
                    onClick={() => onPage(pagination.page + 1)}
                >
                    Siguiente
                    <ChevronRight size={13} />
                </Button>
            </div>
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
            only: ['drivers', 'pagination'],
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
                    total={pagination.total}
                    onRefresh={refresh}
                    refreshing={refreshing}
                />

                <FilterBar
                    filters={filters}
                    options={filterOptions}
                    onApply={applyFilters}
                />

                <DriversTable
                    rows={drivers}
                    onSelect={handleSelect}
                    empty={<RosterEmptyState filtered={hasActiveFilters} />}
                />

                <RosterFooter
                    pagination={pagination}
                    shown={drivers.length}
                    onPage={goToPage}
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
