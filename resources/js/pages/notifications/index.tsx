import { Head, router, usePage } from '@inertiajs/react';
import {
    Bell,
    BellOff,
    CircleSlash,
    RefreshCw,
    Send,
    Siren,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import {
    ClearFiltersButton,
    FilterDropdown,
    ListFooter,
} from '@/components/sam/list';
import { NotificationsTable } from '@/components/sam/notifications/notifications-table';
import { PulseStat, PulseStrip } from '@/components/sam/pulse-strip';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { cn } from '@/lib/utils';
import type {
    NotificationFilterOptions,
    NotificationFilters,
    NotificationsIndexProps,
    NotificationsPagination,
    NotificationsSummary,
} from '@/types/notifications';

// ---- PageHead ----

function PageHead({
    total,
    unread,
    onRefresh,
    refreshing,
}: {
    total: number;
    unread: number | null;
    onRefresh: () => void;
    refreshing: boolean;
}) {
    return (
        <PageHeader
            title="Notificaciones"
            meta={
                <span className="text-xs text-fg-3">
                    <span className="font-medium text-fg-1">{total}</span>{' '}
                    {total === 1 ? 'notificación' : 'notificaciones'}
                    {unread !== null && unread > 0 && (
                        <>
                            {' · '}
                            <span className="text-primary">
                                {unread} sin leer
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

function CenterPulse({
    summary,
    filters,
    onApply,
}: {
    summary: NotificationsSummary;
    filters: NotificationFilters;
    onApply: (next: NotificationFilters) => void;
}) {
    return (
        <PulseStrip>
            <PulseStat
                label="Sin leer"
                value={summary.unread}
                icon={Bell}
                tone={summary.unread > 0 ? 'primary' : 'neutral'}
                hint="para ti, en este equipo"
                onClick={() => onApply({ ...filters, unread: !filters.unread })}
                active={filters.unread}
            />
            <PulseStat
                label="Enviadas 24 h"
                value={summary.sent24h}
                icon={Send}
                tone="ok"
                hint="salieron por algún canal"
            />
            <PulseStat
                label="No entregadas 24 h"
                value={summary.undelivered24h}
                icon={CircleSlash}
                tone={summary.undelivered24h > 0 ? 'critical' : 'neutral'}
                hint="fallidas o canceladas"
                onClick={() =>
                    onApply({
                        ...filters,
                        status: filters.status === 'failed' ? null : 'failed',
                    })
                }
                active={filters.status === 'failed'}
            />
            <PulseStat
                label="Críticas 24 h"
                value={summary.critical24h}
                icon={Siren}
                tone={summary.critical24h > 0 ? 'critical' : 'neutral'}
                hint="prioridad crítica"
                onClick={() =>
                    onApply({
                        ...filters,
                        priority:
                            filters.priority === 'critical' ? null : 'critical',
                    })
                }
                active={filters.priority === 'critical'}
            />
        </PulseStrip>
    );
}

// ---- FilterBar ----

interface FilterBarProps {
    filters: NotificationFilters;
    options: NotificationFilterOptions;
    onApply: (next: NotificationFilters) => void;
}

function FilterBar({ filters, options, onApply }: FilterBarProps) {
    const hasActive =
        filters.status !== null || filters.priority !== null || filters.unread;

    return (
        <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-border bg-background px-5 py-2">
            <button
                type="button"
                aria-pressed={filters.unread}
                onClick={() => onApply({ ...filters, unread: !filters.unread })}
                className={cn(
                    'flex items-center gap-1 rounded-full border px-2.5 py-1 text-2xs font-medium transition-colors',
                    filters.unread
                        ? 'border-primary/40 bg-primary/10 text-primary'
                        : 'border-border bg-surface-1 text-fg-2 hover:border-border-strong hover:text-fg-1',
                )}
            >
                {filters.unread ? <BellOff size={11} /> : <Bell size={11} />}
                Solo no leídas
            </button>

            <FilterDropdown
                label="Estado"
                value={filters.status}
                options={options.statuses}
                allLabel="Todas"
                onChange={(status) => onApply({ ...filters, status })}
            />

            <FilterDropdown
                label="Prioridad"
                value={filters.priority}
                options={options.priorities}
                allLabel="Todas"
                onChange={(priority) => onApply({ ...filters, priority })}
            />

            {hasActive && (
                <ClearFiltersButton
                    onClick={() =>
                        onApply({ status: null, priority: null, unread: false })
                    }
                />
            )}
        </div>
    );
}

// ---- Empty state ----

function CenterEmptyState({ filtered }: { filtered: boolean }) {
    return (
        <EmptyState
            className="min-h-0 flex-1"
            icon={Bell}
            title={filtered ? 'Sin resultados' : 'Sin notificaciones'}
            description={
                filtered
                    ? 'Ninguna notificación coincide con los filtros aplicados.'
                    : 'Cuando el sistema genere notificaciones para tu equipo aparecerán aquí: incidentes, escalaciones, alertas de riesgo y automatizaciones.'
            }
        />
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
    const summary = pageProps.summary ?? null;

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
            only: ['notifications', 'pagination', 'summary'],
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
                        only: ['notifications', 'pagination', 'summary'],
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
                    unread={summary?.unread ?? null}
                    onRefresh={refresh}
                    refreshing={refreshing}
                />

                {summary && (
                    <CenterPulse
                        summary={summary}
                        filters={filters}
                        onApply={applyFilters}
                    />
                )}

                <FilterBar
                    filters={filters}
                    options={filterOptions}
                    onApply={applyFilters}
                />

                <NotificationsTable
                    rows={notifications}
                    onMarkRead={markRead}
                    onOpenSource={openSource}
                    empty={<CenterEmptyState filtered={hasActiveFilters} />}
                />

                <ListFooter
                    pagination={pagination}
                    shown={notifications.length}
                    onPage={goToPage}
                    noun={['notificación', 'notificaciones']}
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
