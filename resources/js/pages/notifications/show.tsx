import { Head, Link, usePage } from '@inertiajs/react';
import {
    Bell,
    ChevronLeft,
    ExternalLink,
    Globe,
    Mail,
    MessageCircle,
    MessageSquare,
    Phone,
    Send,
    Smartphone,
    Webhook,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { MetaChip } from '@/components/sam/meta-chip';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    DeliveryTone,
    NotificationDeliveryRow,
    NotificationShowProps,
} from '@/types/notifications';

const CHANNEL_ICONS: Record<string, LucideIcon> = {
    email: Mail,
    sms: MessageSquare,
    whatsapp: MessageCircle,
    voice: Phone,
    push: Smartphone,
    web: Globe,
    slack: Send,
    webhook: Webhook,
};

const TONE_STYLES: Record<DeliveryTone, string> = {
    success: 'bg-severity-low/15 text-severity-low',
    danger: 'bg-severity-critical/15 text-severity-critical',
    muted: 'bg-surface-3 text-fg-3',
    pending: 'bg-severity-medium/15 text-severity-medium',
};

const NOTIFICATION_STATUS_LABELS: Record<string, string> = {
    pending: 'Pendiente',
    queued: 'En cola',
    partially_sent: 'Parcialmente enviada',
    sent: 'Enviada',
    failed: 'Fallida',
    cancelled: 'Cancelada',
};

function DeliveryStatus({ delivery }: { delivery: NotificationDeliveryRow }) {
    return (
        <div className="flex flex-col items-start gap-1">
            <span
                className={cn(
                    'inline-flex items-center rounded-full px-2 py-0.5 text-3xs font-semibold',
                    TONE_STYLES[delivery.tone],
                )}
            >
                {delivery.statusLabel}
            </span>
            {delivery.reason && (
                <span className="text-2xs text-fg-3">{delivery.reason}</span>
            )}
        </div>
    );
}

function Timestamp({ label, iso }: { label: string; iso: string | null }) {
    if (iso === null) {
        return null;
    }

    return (
        <span className="text-2xs text-fg-3">
            {label}{' '}
            <span className="text-fg-2 tabular-nums">
                {formatDateTime(iso)}
            </span>
        </span>
    );
}

function ProviderEvents({ delivery }: { delivery: NotificationDeliveryRow }) {
    if (delivery.events.length === 0) {
        return null;
    }

    return (
        <details className="mt-1 text-2xs text-fg-3">
            <summary className="cursor-pointer select-none hover:text-fg-2">
                Historial del proveedor ({delivery.events.length})
            </summary>
            <ol className="mt-1.5 flex flex-col gap-1 border-l border-border pl-3">
                {delivery.events.map((event, index) => (
                    <li
                        key={`${event.status}-${event.at ?? index}`}
                        className="flex flex-wrap items-center gap-x-2"
                    >
                        <span className="font-medium text-fg-2">
                            {event.label}
                        </span>
                        {event.errorCode && (
                            <span className="font-mono">
                                error {event.errorCode}
                            </span>
                        )}
                        {event.at && (
                            <span className="tabular-nums">
                                {formatDateTime(event.at)}
                            </span>
                        )}
                        {event.source === 'poll' && (
                            <span className="italic">(consultado)</span>
                        )}
                    </li>
                ))}
            </ol>
        </details>
    );
}

function DeliveryItem({ delivery }: { delivery: NotificationDeliveryRow }) {
    const Icon = CHANNEL_ICONS[delivery.channel.type ?? ''] ?? Bell;

    return (
        <li className="flex flex-col gap-2 border-t border-border/60 py-3 first:border-t-0 sm:flex-row sm:items-start sm:gap-4">
            <div className="flex w-44 shrink-0 items-center gap-2">
                <Icon size={14} className="shrink-0 text-fg-3" />
                <div className="flex min-w-0 flex-col">
                    <span className="text-xs font-medium text-fg-1">
                        {delivery.channel.label ?? delivery.channel.type}
                    </span>
                    {delivery.address && (
                        <span className="truncate font-mono text-3xs text-fg-3">
                            {delivery.address}
                        </span>
                    )}
                </div>
            </div>

            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <DeliveryStatus delivery={delivery} />
                <div className="flex flex-wrap gap-x-3 gap-y-0.5">
                    <Timestamp label="Aceptado" iso={delivery.acceptedAt} />
                    <Timestamp label="Contestada" iso={delivery.answeredAt} />
                    <Timestamp label="Entregado" iso={delivery.deliveredAt} />
                    <Timestamp label="Leído" iso={delivery.readAt} />
                    <Timestamp label="Falló" iso={delivery.failedAt} />
                </div>
                <ProviderEvents delivery={delivery} />
            </div>

            <div className="flex shrink-0 flex-wrap items-center gap-1.5">
                {delivery.isFallback && <MetaChip>Canal alterno</MetaChip>}
                {delivery.attempts > 1 && (
                    <MetaChip>{delivery.attempts} intentos</MetaChip>
                )}
            </div>
        </li>
    );
}

function groupByRecipient(
    deliveries: NotificationDeliveryRow[],
): { key: number; name: string; rows: NotificationDeliveryRow[] }[] {
    const groups = new Map<
        number,
        { key: number; name: string; rows: NotificationDeliveryRow[] }
    >();

    for (const delivery of deliveries) {
        const key = delivery.recipient.id;
        const group = groups.get(key) ?? {
            key,
            name: delivery.recipient.name ?? 'Destinatario sin nombre',
            rows: [],
        };

        group.rows.push(delivery);
        groups.set(key, group);
    }

    return [...groups.values()];
}

export default function NotificationShow() {
    const page = usePage();
    const { notification, deliveries } =
        page.props as unknown as NotificationShowProps;
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const groups = groupByRecipient(deliveries);

    return (
        <>
            <Head title="Detalle de entregas" />
            <div className="flex min-h-0 flex-1 flex-col overflow-y-auto">
                <PageHeader
                    title={notification.subject ?? notification.type}
                    description={notification.bodyPreview ?? undefined}
                    meta={
                        <MetaChip>
                            {NOTIFICATION_STATUS_LABELS[notification.status] ??
                                notification.status}
                        </MetaChip>
                    }
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
                                    <Link href={`/${teamSlug}/notifications`}>
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

NotificationShow.layout = (props: {
    currentTeam?: { slug: string } | null;
    notification?: { id: number } | null;
}) => ({
    breadcrumbs: [
        {
            title: 'Notificaciones',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/notifications`
                : '/notifications',
        },
        ...(props.notification && props.currentTeam
            ? [
                  {
                      title: `Entregas #${props.notification.id}`,
                      href: `/${props.currentTeam.slug}/notifications/${props.notification.id}`,
                  },
              ]
            : []),
    ],
});
