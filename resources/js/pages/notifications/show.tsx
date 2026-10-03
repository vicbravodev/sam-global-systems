import type { SharedPageProps } from '@inertiajs/core';
import { Head, Link, usePage } from '@inertiajs/react';
import { Bell, ChevronLeft, ExternalLink } from 'lucide-react';
import { MetaChip } from '@/components/sam/meta-chip';
import { DeliveryItem } from '@/components/sam/notifications/detail/delivery-item';
import { groupByRecipient } from '@/components/sam/notifications/detail/lib';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import notificationRoutes from '@/routes/notifications';
import type { NotificationShowProps } from '@/types/notifications';

export default function NotificationShow({
    notification,
    deliveries,
}: NotificationShowProps) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const groups = groupByRecipient(deliveries);

    return (
        <>
            <Head title="Detalle de entregas" />
            <div className="flex min-h-0 flex-1 flex-col overflow-y-auto">
                <PageHeader
                    title={notification.subject ?? notification.type}
                    description={notification.bodyPreview ?? undefined}
                    meta={<MetaChip>{notification.statusLabel}</MetaChip>}
                    actions={
                        <div className="flex items-center gap-2">
                            {notification.sourceUrl && (
                                <Button variant="ghost" size="sm" asChild>
                                    <Link href={notification.sourceUrl}>
                                        <ExternalLink size={13} />
                                        Ver incidente
                                    </Link>
                                </Button>
                            )}
                            {teamSlug !== null && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={notificationRoutes.index(
                                            teamSlug,
                                        )}
                                    >
                                        <ChevronLeft size={13} />
                                        Notificaciones
                                    </Link>
                                </Button>
                            )}
                        </div>
                    }
                    className="shrink-0 border-b border-border bg-surface-1 px-5 py-3"
                />

                <div className="flex flex-col gap-4 p-5">
                    {notification.statusReason && (
                        <p className="text-xs text-fg-3">
                            {notification.statusReason}
                        </p>
                    )}

                    {groups.length === 0 ? (
                        <EmptyState
                            className="min-h-0 py-10"
                            icon={Bell}
                            title="Sin entregas"
                            description="Esta notificación no llegó a intentarse por ningún canal."
                        />
                    ) : (
                        groups.map((group) => (
                            <Card key={group.key}>
                                <CardHeader>
                                    <CardTitle className="text-sm">
                                        {group.name}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <ul className="flex flex-col">
                                        {group.rows.map((delivery) => (
                                            <DeliveryItem
                                                key={delivery.id}
                                                delivery={delivery}
                                            />
                                        ))}
                                    </ul>
                                </CardContent>
                            </Card>
                        ))
                    )}
                </div>
            </div>
        </>
    );
}

NotificationShow.layout = (
    props: SharedPageProps & Partial<NotificationShowProps>,
) => ({
    breadcrumbs: [
        {
            title: 'Notificaciones',
            href: props.currentTeam
                ? notificationRoutes.index.url(props.currentTeam.slug)
                : '#',
        },
        ...(props.notification && props.currentTeam
            ? [
                  {
                      title: `Entregas #${props.notification.id}`,
                      href: notificationRoutes.show.url([
                          props.currentTeam.slug,
                          props.notification.id,
                      ]),
                  },
              ]
            : []),
    ],
});
