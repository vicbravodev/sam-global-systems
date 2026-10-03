import type { SharedPageProps } from '@inertiajs/core';
import { Head, router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { EMPTY_PAGINATION, ListFooter } from '@/components/sam/list';
import { ListEmptyState, ListPage } from '@/components/sam/list-page';
import {
    EMPTY_NOTIFICATION_FILTERS,
    NotificationsFilterBar,
} from '@/components/sam/notifications/notifications-filter-bar';
import { NotificationsPulse } from '@/components/sam/notifications/notifications-pulse';
import { NotificationsTable } from '@/components/sam/notifications/notifications-table';
import { useServerList } from '@/hooks/use-server-list';
import { useBroadcastReload } from '@/hooks/use-team-broadcasts';
import notificationRoutes from '@/routes/notifications';
import type {
    NotificationFilterOptions,
    NotificationsIndexProps,
} from '@/types/notifications';

const EMPTY_OPTIONS: NotificationFilterOptions = {
    statuses: [],
    priorities: [],
};

export default function NotificationsIndex(pageProps: NotificationsIndexProps) {
    // A notification addressed to me landed: refresh the list and counters.
    useBroadcastReload({
        'notification.pushed': ['notifications', 'pagination', 'summary'],
    });
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const notifications = pageProps.notifications ?? [];
    const pagination = pageProps.pagination ?? EMPTY_PAGINATION;
    const filterOptions = pageProps.filterOptions ?? EMPTY_OPTIONS;
    const summary = pageProps.summary ?? null;

    const list = useServerList({
        only: ['notifications', 'pagination'],
        refreshOnly: ['notifications', 'pagination', 'summary'],
        filters: pageProps.filters ?? EMPTY_NOTIFICATION_FILTERS,
        emptyFilters: EMPTY_NOTIFICATION_FILTERS,
    });

    const markRead = (id: number) => {
        if (teamSlug !== null) {
            router.post(
                notificationRoutes.read.url([teamSlug, id]),
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
                        <NotificationsPulse
                            summary={summary}
                            filters={list.filters}
                            onApply={list.apply}
                        />
                    )
                }
                filters={
                    <NotificationsFilterBar
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

NotificationsIndex.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'Notificaciones',
            href: props.currentTeam
                ? notificationRoutes.index.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
