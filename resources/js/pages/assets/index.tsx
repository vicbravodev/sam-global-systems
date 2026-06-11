import { Head, router, usePage } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Filter,
    RefreshCw,
    Search,
    Truck,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { AssetsTable } from '@/components/sam/assets/assets-table';
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
import { TEAM_BROADCAST_EVENT_NAME } from '@/hooks/use-team-broadcasts';
import type { TeamBroadcastDetail } from '@/hooks/use-team-broadcasts';
import { cn } from '@/lib/utils';
import type {
    AssetFilterOptions,
    AssetFilters,
    AssetsIndexProps,
    AssetsPagination,
} from '@/types/assets';

// Broadcast events that refresh the fleet list. Location polls can arrive in
// bursts (one event per asset), so reloads are debounced below.
const RELOAD_EVENTS = new Set([
    'asset.location_updated',
    'asset.status_changed',
]);

const RELOAD_DEBOUNCE_MS = 2000;

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
            title="Flota"
            meta={
                <span className="text-[12px] text-fg-3">
                    <span className="font-medium text-fg-1">{total}</span>{' '}
                    {total === 1 ? 'activo' : 'activos'}
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
    filters: AssetFilters;
    options: AssetFilterOptions;
    onApply: (next: AssetFilters) => void;
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

    const hasActive =
        filters.q !== null || filters.status !== null || filters.type !== null;

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
            <FilterDropdown
                label="Tipo"
                value={filters.type}
                options={options.types}
                onChange={(v) => onApply({ ...filters, type: v })}
            />

            {hasActive && (
                <button
                    type="button"
                    onClick={() =>
                        onApply({ q: null, status: null, type: null })
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

// ---- Footer / pagination ----

function FleetFooter({
    pagination,
    shown,
    onPage,
}: {
    pagination: AssetsPagination;
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
                {pagination.total === 1 ? 'activo' : 'activos'}
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

function FleetEmptyState({ filtered }: { filtered: boolean }) {
    return (
        <EmptyState
            className="min-h-0 flex-1"
            icon={Truck}
            title={filtered ? 'Sin resultados' : 'Sin activos'}
            description={
                filtered
                    ? 'Ningún activo coincide con los filtros aplicados.'
                    : 'Cuando la sincronización de integraciones registre activos aparecerán aquí.'
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
            only: ['assets', 'pagination'],
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

    // Live updates: location polls and status transitions refresh the list.
    // Bursts are coalesced into a single partial reload.
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
                router.reload({ only: ['assets', 'pagination'] });
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
                    total={pagination.total}
                    onRefresh={refresh}
                    refreshing={refreshing}
                />

                <FilterBar
                    filters={filters}
                    options={filterOptions}
                    onApply={applyFilters}
                />

                <AssetsTable
                    rows={assets}
                    onSelect={handleSelect}
                    empty={<FleetEmptyState filtered={hasActiveFilters} />}
                />

                <FleetFooter
                    pagination={pagination}
                    shown={assets.length}
                    onPage={goToPage}
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
