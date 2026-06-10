import { Head, router, usePage } from '@inertiajs/react';
import {
    Bell,
    ChevronLeft,
    ChevronRight,
    Filter,
    RefreshCw,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { NotificationsTable } from '@/components/sam/notifications/notifications-table';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type {
    NotificationFilterOptions,
    NotificationFilters,
    NotificationsIndexProps,
    NotificationsPagination,
} from '@/types/notifications';

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
        <header className="flex shrink-0 items-center justify-between gap-3 border-b border-border bg-surface-1 px-5 py-3">
            <div className="flex items-center gap-3">
                <h1 className="text-[15px] font-semibold text-fg-1">
                    Notificaciones
                </h1>
                <span className="text-[12px] text-fg-3">
                    <span className="font-medium text-fg-1">{total}</span>{' '}
                    {total === 1 ? 'notificación' : 'notificaciones'}
                </span>
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
        </header>
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
                        Todas
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
    filters: NotificationFilters;
    options: NotificationFilterOptions;
    onApply: (next: NotificationFilters) => void;
}

function FilterBar({ filters, options, onApply }: FilterBarProps) {
    const hasActive =
        filters.status !== null || filters.priority !== null || filters.unread;

    return (
        <div className="flex shrink-0 items-center gap-2 border-b border-border bg-background px-5 py-2">
            <FilterDropdown
                label="Estado"
                value={filters.status}
                options={options.statuses}
                onChange={(v) => onApply({ ...filters, status: v })}
            />

            <FilterDropdown
                label="Prioridad"
                value={filters.priority}
                options={options.priorities}
                onChange={(v) => onApply({ ...filters, priority: v })}
            />

            <button
                type="button"
                onClick={() => onApply({ ...filters, unread: !filters.unread })}
                className={cn(
                    'flex items-center gap-1 rounded-sm border px-2.5 py-1.5 text-[11px] transition-colors',
                    filters.unread
                        ? 'border-primary/40 bg-primary/10 text-primary'
                        : 'border-border bg-surface-1 text-fg-2 hover:border-border-strong',
                )}
            >
                <Bell size={11} />
                Solo no leídas
            </button>

            {hasActive && (
                <button
                    type="button"
                    onClick={() =>
                        onApply({ status: null, priority: null, unread: false })
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

function CenterFooter({
    pagination,
    shown,
    onPage,
}: {
    pagination: NotificationsPagination;
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
                {pagination.total === 1 ? 'notificación' : 'notificaciones'}
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

function CenterEmptyState({ filtered }: { filtered: boolean }) {
    return (
        <div className="flex min-h-0 flex-1 flex-col items-center justify-center gap-3 px-6 py-16 text-center">
            <div className="inline-grid size-12 place-items-center rounded-full border border-border bg-surface-2 text-fg-3">
                <Bell size={22} strokeWidth={1.5} />
            </div>
            <h2 className="text-[15px] font-semibold text-fg-1">
                {filtered ? 'Sin resultados' : 'Sin notificaciones'}
            </h2>
            <p className="max-w-sm text-[12px] leading-[1.5] text-fg-3">
                {filtered
                    ? 'Ninguna notificación coincide con los filtros aplicados.'
                    : 'Cuando el sistema genere notificaciones para tu equipo aparecerán aquí.'}
            </p>
        </div>
    );
}

// ---- Main page ----

const EMPTY_FILTERS: NotificationFilters = {
    status: null,
    priority: null,
    unread: false,
};

const EMPTY_OPTIONS: NotificationFilterOptions = {
    statuses: [],
    priorities: [],
};

const EMPTY_PAGINATION: NotificationsPagination = {
    page: 1,
    perPage: 50,
    total: 0,
    lastPage: 1,
};

export default function NotificationsIndex() {
    const page = usePage();
    const pageProps = page.props as unknown as NotificationsIndexProps;
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const notifications = pageProps.notifications ?? [];
    const pagination = pageProps.pagination ?? EMPTY_PAGINATION;
    const serverFilters = pageProps.filters ?? EMPTY_FILTERS;
    const filterOptions = pageProps.filterOptions ?? EMPTY_OPTIONS;

    const [refreshing, setRefreshing] = useState(false);
    const [filters, setFilters] = useState<NotificationFilters>(serverFilters);

    // Re-sync local filter state if the server echoes a different set
    // (e.g. after a browser back/forward navigation).
    useEffect(() => {
        setFilters(serverFilters);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [serverFilters.status, serverFilters.priority, serverFilters.unread]);

    const refresh = () => {
        setRefreshing(true);
        router.reload({
            only: ['notifications', 'pagination'],
            onFinish: () => setRefreshing(false),
        });
    };

    const applyFilters = useCallback((next: NotificationFilters) => {
        setFilters(next);
        router.reload({
            only: ['notifications', 'pagination', 'filters'],
            data: {
                status: next.status ?? undefined,
                priority: next.priority ?? undefined,
                unread: next.unread ? 1 : undefined,
                // Changing filters always restarts at the first page.
                page: undefined,
            },
        });
    }, []);

    const goToPage = useCallback((target: number) => {
        router.reload({
            only: ['notifications', 'pagination'],
            data: { page: target },
        });
    }, []);

    const markRead = useCallback(
        (id: number) => {
            if (teamSlug !== null) {
                router.post(
                    `/${teamSlug}/notifications/${id}/read`,
                    {},
                    {
                        preserveScroll: true,
                        only: ['notifications', 'pagination'],
                    },
                );
            }
        },
        [teamSlug],
    );

    const openSource = useCallback((url: string) => {
        router.visit(url);
    }, []);

    const hasActiveFilters =
        serverFilters.status !== null ||
        serverFilters.priority !== null ||
        serverFilters.unread;

    return (
        <>
            <Head title="Notificaciones" />
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

                {notifications.length === 0 ? (
                    <CenterEmptyState filtered={hasActiveFilters} />
                ) : (
                    <NotificationsTable
                        rows={notifications}
                        onMarkRead={markRead}
                        onOpenSource={openSource}
                    />
                )}

                <CenterFooter
                    pagination={pagination}
                    shown={notifications.length}
                    onPage={goToPage}
                />
            </div>
        </>
    );
}

NotificationsIndex.layout = (props: {
    currentTeam?: { slug: string } | null;
}) => ({
    breadcrumbs: [
        {
            title: 'Notificaciones',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/notifications`
                : '/notifications',
        },
    ],
});
