import { Head, router, usePage } from '@inertiajs/react';
import {
    Bell,
    BellOff,
    CircleSlash,
    Send,
    Siren,
    TriangleAlert,
} from 'lucide-react';
import {
    ClearFiltersButton,
    EMPTY_PAGINATION,
    FilterDropdown,
    ListFooter,
} from '@/components/sam/list';
import { ListEmptyState, ListPage } from '@/components/sam/list-page';
import { NotificationsTable } from '@/components/sam/notifications/notifications-table';
import { PulseStat, PulseStrip } from '@/components/sam/pulse-strip';
import { useServerList } from '@/hooks/use-server-list';
import { useBroadcastReload } from '@/hooks/use-team-broadcasts';
import { cn } from '@/lib/utils';
import type {
    NotificationFilterOptions,
    NotificationFilters,
    NotificationsIndexProps,
    NotificationsSummary,
} from '@/types/notifications';

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
                hint="con entregas fallidas, fallidas o canceladas"
                onClick={() =>
                    onApply({ ...filters, failures: !filters.failures })
                }
                active={filters.failures}
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

const EMPTY_FILTERS: NotificationFilters = {
    status: null,
    priority: null,
    unread: false,
    failures: false,
};

interface FilterBarProps {
    filters: NotificationFilters;
    options: NotificationFilterOptions;
    onApply: (next: NotificationFilters) => void;
}

function FilterBar({ filters, options, onApply }: FilterBarProps) {
    const hasActive =
        filters.status !== null ||
        filters.priority !== null ||
        filters.unread ||
        filters.failures;

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
                Mis no leídas
            </button>

            <button
                type="button"
                aria-pressed={filters.failures}
                onClick={() =>
                    onApply({ ...filters, failures: !filters.failures })
                }
                className={cn(
                    'flex items-center gap-1 rounded-full border px-2.5 py-1 text-2xs font-medium transition-colors',
                    filters.failures
                        ? 'border-severity-critical/40 bg-severity-critical/10 text-severity-critical'
                        : 'border-border bg-surface-1 text-fg-2 hover:border-border-strong hover:text-fg-1',
                )}
            >
                <TriangleAlert size={11} />
                No entregadas
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
                <ClearFiltersButton onClick={() => onApply(EMPTY_FILTERS)} />
            )}
        </div>
    );
}

// ---- Main page ----

const EMPTY_OPTIONS: NotificationFilterOptions = {
    statuses: [],
    priorities: [],
};

export default function NotificationsIndex() {
    // A notification addressed to me landed: refresh the list and counters.
    useBroadcastReload({
        'notification.pushed': ['notifications', 'pagination', 'summary'],
    });
    const page = usePage();
    const pageProps = page.props as unknown as NotificationsIndexProps;
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const notifications = pageProps.notifications ?? [];
    const pagination = pageProps.pagination ?? EMPTY_PAGINATION;
    const filterOptions = pageProps.filterOptions ?? EMPTY_OPTIONS;
    const summary = pageProps.summary ?? null;

    const list = useServerList({
        only: ['notifications', 'pagination'],
        refreshOnly: ['notifications', 'pagination', 'summary'],
        filters: pageProps.filters ?? EMPTY_FILTERS,
        emptyFilters: EMPTY_FILTERS,
    });

    const markRead = (id: number) => {
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
    };

    const openUrl = (url: string) => {
        router.visit(url);
    };

    const total = pagination.total;
    const unread = summary?.unread ?? 0;

    return (
        <>
            <Head title="Notificaciones" />
            <ListPage
                title="Notificaciones"
                meta={
                    <span className="text-xs text-fg-3">
                        <span className="font-medium text-fg-1">{total}</span>{' '}
                        {total === 1 ? 'notificación' : 'notificaciones'}
                        {unread > 0 && (
                            <>
                                {' · '}
                                <span className="text-primary">
                                    {unread} sin leer
                                </span>
                            </>
                        )}
                    </span>
                }
                onRefresh={list.refresh}
                refreshing={list.refreshing}
                pulse={
                    summary && (
                        <CenterPulse
                            summary={summary}
                            filters={list.filters}
                            onApply={list.apply}
                        />
                    )
                }
                filters={
                    <FilterBar
                        filters={list.filters}
                        options={filterOptions}
                        onApply={list.apply}
                    />
                }
                footer={
                    <ListFooter
                        pagination={pagination}
                        shown={notifications.length}
                        onPage={list.goToPage}
                        noun={['notificación', 'notificaciones']}
                    />
                }
            >
                <NotificationsTable
                    rows={notifications}
                    onMarkRead={markRead}
                    onOpenSource={openUrl}
                    onOpenDetail={openUrl}
                    empty={
                        <ListEmptyState
                            icon={Bell}
                            filtered={list.hasActiveFilters}
                            title="Sin notificaciones"
                            description="Cuando el sistema genere notificaciones para tu equipo aparecerán aquí: incidentes, escalaciones, alertas de riesgo y automatizaciones."
                            filteredDescription="Ninguna notificación coincide con los filtros aplicados."
                        />
                    }
                />
            </ListPage>
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
