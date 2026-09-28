import {
    ArrowUpRight,
    Bell,
    Check,
    ExternalLink,
    Mail,
    MessageCircle,
    MessageSquare,
    Phone,
    Server,
    ShieldAlert,
    Smartphone,
    User,
    Users,
    Webhook,
    Zap,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import * as React from 'react';
import { CellEmpty, DataTable } from '@/components/sam/data-table';
import type { DataTableColumn } from '@/components/sam/data-table';
import { RelativeTime } from '@/components/sam/relative-time';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatDateTime } from '@/lib/format';
import { dayLabel, formatClock, minutesSince } from '@/lib/time';
import { cn } from '@/lib/utils';
import type {
    NotificationChannelSummary,
    NotificationPriorityValue,
    NotificationRow,
    NotificationStatusValue,
} from '@/types/notifications';

const PRIORITY_STYLES: Record<NotificationPriorityValue, string> = {
    low: 'border-border bg-surface-3 text-fg-3',
    normal: 'border-border bg-surface-3 text-fg-2',
    high: 'border-severity-medium/40 bg-severity-medium/15 text-severity-medium',
    critical:
        'border-severity-critical/40 bg-severity-critical/15 text-severity-critical',
};

const PRIORITY_LABELS: Record<NotificationPriorityValue, string> = {
    low: 'Baja',
    normal: 'Normal',
    high: 'Alta',
    critical: 'Crítica',
};

const PRIORITY_RANK: Record<NotificationPriorityValue, number> = {
    low: 0,
    normal: 1,
    high: 2,
    critical: 3,
};

const STATUS_LABELS: Record<NotificationStatusValue, string> = {
    pending: 'Pendiente',
    queued: 'En cola',
    partially_sent: 'Parcial',
    sent: 'Enviada',
    failed: 'Fallida',
    cancelled: 'Cancelada',
};

const STATUS_DOT: Record<NotificationStatusValue, string> = {
    pending: 'bg-fg-3',
    queued: 'bg-severity-info motion-safe:animate-pulse',
    partially_sent: 'bg-severity-medium',
    sent: 'bg-severity-low',
    failed: 'bg-severity-critical',
    cancelled: 'bg-fg-disabled',
};

const CHANNEL_ICONS: Record<string, LucideIcon> = {
    email: Mail,
    sms: MessageSquare,
    whatsapp: MessageCircle,
    push: Smartphone,
    web: Bell,
    slack: MessageSquare,
    webhook: Webhook,
    voice: Phone,
};

const CHANNEL_LABELS: Record<string, string> = {
    email: 'Email',
    sms: 'SMS',
    whatsapp: 'WhatsApp',
    push: 'Push',
    web: 'Web',
    slack: 'Slack',
    webhook: 'Webhook',
    voice: 'Llamada',
};

const DELIVERY_LABELS: Record<string, string> = {
    pending: 'pendiente',
    queued: 'en cola',
    sending: 'enviando',
    delivered: 'entregado',
    failed: 'falló',
    bounced: 'rebotó',
    retrying: 'reintentando',
    cancelled: 'cancelado',
    skipped: 'omitido (sin contacto)',
};

const DELIVERY_STYLES: Record<string, string> = {
    delivered: 'border-severity-low/40 bg-severity-low/10 text-severity-low',
    failed: 'border-severity-critical/40 bg-severity-critical/10 text-severity-critical',
    bounced:
        'border-severity-critical/40 bg-severity-critical/10 text-severity-critical',
    retrying:
        'border-severity-medium/40 bg-severity-medium/10 text-severity-medium',
    skipped: 'border-dashed border-border bg-transparent text-fg-3',
    cancelled: 'border-dashed border-border bg-transparent text-fg-3',
};

/** Glyph by notification family (incident.*, driver.*, action.*, system.*). */
function typeIcon(type: string, sourceType: string): LucideIcon {
    const family = type.split('.')[0];

    if (family === 'incident' || sourceType === 'incident') {
        return ShieldAlert;
    }

    if (family === 'driver') {
        return User;
    }

    if (family === 'action' || sourceType === 'action_execution') {
        return Zap;
    }

    if (family === 'escalation' || sourceType === 'escalation') {
        return ArrowUpRight;
    }

    if (family === 'system' || sourceType === 'system_event') {
        return Server;
    }

    return Bell;
}

function ChannelChips({
    channels,
}: {
    channels: NotificationChannelSummary[];
}) {
    if (channels.length === 0) {
        return <CellEmpty />;
    }

    return (
        <span className="flex flex-wrap items-center gap-1">
            {channels.map((channel) => {
                const Icon = CHANNEL_ICONS[channel.type] ?? Bell;
                const label = CHANNEL_LABELS[channel.type] ?? channel.type;
                const status =
                    DELIVERY_LABELS[channel.status] ?? channel.status;

                return (
                    <Tooltip key={channel.type}>
                        <TooltipTrigger asChild>
                            <span
                                className={cn(
                                    'inline-flex items-center gap-1 rounded-sm border px-1.5 py-0.5 text-3xs font-semibold',
                                    DELIVERY_STYLES[channel.status] ??
                                        'border-border bg-surface-3 text-fg-2',
                                )}
                            >
                                <Icon
                                    size={10}
                                    strokeWidth={2}
                                    aria-hidden="true"
                                />
                                {label}
                                {channel.count > 1 && (
                                    <span className="font-mono opacity-70">
                                        ×{channel.count}
                                    </span>
                                )}
                            </span>
                        </TooltipTrigger>
                        <TooltipContent side="top">
                            {label}: {status}
                        </TooltipContent>
                    </Tooltip>
                );
            })}
        </span>
    );
}

function StatusCell({
    status,
    reason,
}: {
    status: NotificationStatusValue;
    reason: string | null;
}) {
    return (
        <span className="flex min-w-0 flex-col">
            <span
                className={cn(
                    'inline-flex items-center gap-1.5 text-2xs',
                    status === 'failed'
                        ? 'font-medium text-severity-critical'
                        : status === 'cancelled'
                          ? 'text-fg-3'
                          : 'text-fg-2',
                )}
            >
                <span
                    className={cn('size-1.5 rounded-full', STATUS_DOT[status])}
                    aria-hidden="true"
                />
                {STATUS_LABELS[status]}
            </span>
            {reason && (
                <span
                    className="line-clamp-2 text-3xs text-fg-3"
                    title={reason}
                >
                    {reason}
                </span>
            )}
        </span>
    );
}

interface NotificationsTableProps {
    rows: NotificationRow[];
    onMarkRead: (id: number) => void;
    onOpenSource: (url: string) => void;
    empty?: React.ReactNode;
}

export function NotificationsTable({
    rows,
    onMarkRead,
    onOpenSource,
    empty,
}: NotificationsTableProps) {
    const columns = React.useMemo<DataTableColumn<NotificationRow>[]>(
        () => [
            {
                key: 'notification',
                header: 'Notificación',
                sortValue: (notification) =>
                    notification.subject ?? notification.type,
                cell: (notification) => {
                    const Icon = typeIcon(
                        notification.type,
                        notification.sourceType,
                    );

                    return (
                        <span className="flex items-start gap-2.5">
                            <span className="relative mt-0.5 grid size-7 shrink-0 place-items-center rounded-md border border-border bg-surface-2 text-fg-2">
                                <Icon
                                    size={13}
                                    strokeWidth={1.75}
                                    aria-hidden="true"
                                />
                                {!notification.isRead && (
                                    <span
                                        className="absolute -top-1 -right-1 size-2 rounded-full bg-primary ring-2 ring-surface-1"
                                        aria-label="No leída"
                                    />
                                )}
                            </span>
                            <span className="flex min-w-0 flex-col">
                                <span
                                    className={cn(
                                        'truncate text-sm text-fg-1',
                                        !notification.isRead && 'font-semibold',
                                    )}
                                >
                                    {notification.subject ?? notification.type}
                                </span>
                                {notification.bodyPreview && (
                                    <span className="line-clamp-1 text-2xs text-fg-2">
                                        {notification.bodyPreview}
                                    </span>
                                )}
                                <span className="font-mono text-3xs text-fg-3">
                                    {notification.type}
                                </span>
                            </span>
                        </span>
                    );
                },
            },
            {
                key: 'priority',
                header: 'Prioridad',
                width: 'w-24',
                sortValue: (notification) =>
                    PRIORITY_RANK[notification.priority],
                cell: (notification) => (
                    <span
                        className={cn(
                            'inline-flex items-center rounded-sm border px-1.5 py-0.5 text-3xs font-semibold tracking-label',
                            PRIORITY_STYLES[notification.priority],
                        )}
                    >
                        {PRIORITY_LABELS[notification.priority]}
                    </span>
                ),
            },
            {
                key: 'channels',
                header: 'Canales',
                width: 'w-52',
                cell: (notification) => (
                    <ChannelChips channels={notification.channels ?? []} />
                ),
            },
            {
                key: 'recipients',
                header: 'Para',
                width: 'w-16',
                sortValue: (notification) => notification.recipientsCount,
                cell: (notification) =>
                    notification.recipientsCount > 0 ? (
                        <span className="inline-flex items-center gap-1 font-mono text-2xs text-fg-2 tabular-nums">
                            <Users size={11} aria-hidden="true" />
                            {notification.recipientsCount}
                        </span>
                    ) : (
                        <CellEmpty />
                    ),
            },
            {
                key: 'status',
                header: 'Estado',
                width: 'w-48',
                sortValue: (notification) => STATUS_LABELS[notification.status],
                cell: (notification) => (
                    <StatusCell
                        status={notification.status}
                        reason={notification.statusReason ?? null}
                    />
                ),
            },
            {
                key: 'date',
                header: 'Cuándo',
                width: 'w-32',
                sortValue: (notification) => {
                    const iso = notification.sentAt ?? notification.createdAt;

                    return iso ? Date.parse(iso) : null;
                },
                cell: (notification) => {
                    const iso = notification.sentAt ?? notification.createdAt;

                    if (!iso) {
                        return <CellEmpty />;
                    }

                    return (
                        <span
                            className="flex flex-col"
                            title={formatDateTime(iso)}
                        >
                            <RelativeTime
                                minutes={minutesSince(iso)}
                                className="text-fg-1"
                            />
                            <span className="font-mono text-3xs text-fg-3 tabular-nums">
                                {dayLabel(iso)} · {formatClock(iso)}
                            </span>
                        </span>
                    );
                },
            },
            {
                key: 'action',
                header: '',
                width: 'w-44',
                align: 'right',
                cell: (notification) => (
                    <span className="flex items-center justify-end gap-1.5">
                        {notification.sourceUrl && (
                            <button
                                type="button"
                                className="flex cursor-pointer items-center gap-1 rounded-sm border border-border px-2 py-1 text-2xs text-primary transition-colors hover:border-primary/40"
                                onClick={() =>
                                    onOpenSource(
                                        notification.sourceUrl as string,
                                    )
                                }
                            >
                                <ExternalLink size={11} />
                                Incidente
                            </button>
                        )}
                        {!notification.isRead && (
                            <button
                                type="button"
                                className="flex cursor-pointer items-center gap-1 rounded-sm border border-border px-2 py-1 text-2xs text-fg-2 transition-colors hover:border-border-strong hover:text-fg-1"
                                onClick={() => onMarkRead(notification.id)}
                            >
                                <Check size={11} />
                                Leída
                            </button>
                        )}
                    </span>
                ),
            },
        ],
        [onMarkRead, onOpenSource],
    );

    // Delivery columns only earn their space once some notification on the
    // page actually went through a channel / resolved recipients.
    const visible = React.useMemo(
        () =>
            columns.filter((column) => {
                if (column.key === 'channels') {
                    return rows.some((n) => (n.channels ?? []).length > 0);
                }

                if (column.key === 'recipients') {
                    return rows.some((n) => n.recipientsCount > 0);
                }

                return true;
            }),
        [columns, rows],
    );

    return (
        <DataTable
            columns={visible}
            rows={rows}
            rowKey={(notification) => notification.id}
            density="relaxed"
            empty={empty}
        />
    );
}
