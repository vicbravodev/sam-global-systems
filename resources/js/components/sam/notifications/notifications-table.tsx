import {
    ArrowUpRight,
    Bell,
    Check,
    ExternalLink,
    ListChecks,
    Mail,
    MessageCircle,
    MessageSquare,
    Phone,
    Server,
    ShieldAlert,
    Smartphone,
    Truck,
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
import { StatusBadge } from '@/components/sam/status-badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatDateTime } from '@/lib/format';
import { channelLabel } from '@/lib/labels';
import { dayLabel, formatClock, minutesSince } from '@/lib/time';
import { TONE_DOT, TONE_PILL, TONE_TEXT } from '@/lib/tone';
import type { ToneLabel } from '@/lib/tone';
import { cn } from '@/lib/utils';
import type {
    NotificationChannelSummary,
    NotificationDeliverySummary,
    NotificationPriorityValue,
    NotificationRow,
    NotificationStatusTone,
} from '@/types/notifications';
import {
    CHANNEL_DELIVERY,
    NOTIFICATION_STATUS_TONE,
    UNATTEMPTED_DELIVERY,
    notificationPriority,
} from './copy';

const PRIORITY_RANK: Record<NotificationPriorityValue, number> = {
    low: 0,
    normal: 1,
    high: 2,
    critical: 3,
};

const CHANNEL_ICONS: Record<string, LucideIcon> = {
    email: Mail,
    sms: MessageSquare,
    whatsapp: MessageCircle,
    push: Smartphone,
    samsara_driver_app: Truck,
    web: Bell,
    slack: MessageSquare,
    webhook: Webhook,
    voice: Phone,
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
                const label = channelLabel(channel.type);
                const delivery: ToneLabel | undefined =
                    CHANNEL_DELIVERY[channel.status];
                const status = delivery?.label ?? channel.status;

                return (
                    <Tooltip key={channel.type}>
                        <TooltipTrigger asChild>
                            <span
                                className={cn(
                                    'inline-flex items-center gap-1 rounded-sm border px-1.5 py-0.5 text-3xs font-semibold',
                                    TONE_PILL[delivery?.tone ?? 'neutral'],
                                    UNATTEMPTED_DELIVERY.has(channel.status) &&
                                        'border-dashed bg-transparent',
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
    label,
    tone,
    reason,
}: {
    label: string;
    tone: NotificationStatusTone;
    reason: string | null;
}) {
    const color = NOTIFICATION_STATUS_TONE[tone];

    return (
        <span className="flex min-w-0 flex-col">
            <span
                className={cn(
                    'inline-flex items-center gap-1.5 text-2xs',
                    // Sólo los problemas tiñen el texto; lo demás se lee normal.
                    color === 'warn' || color === 'critical'
                        ? TONE_TEXT[color]
                        : tone === 'muted'
                          ? 'text-fg-3'
                          : 'text-fg-2',
                    color === 'critical' && 'font-medium',
                )}
            >
                <span
                    className={cn(
                        'size-1.5 rounded-full',
                        TONE_DOT[color],
                        tone === 'info' && 'motion-safe:animate-pulse',
                    )}
                    aria-hidden="true"
                />
                {label}
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

function DeliveryCell({
    summary,
    onOpen,
}: {
    summary: NotificationDeliverySummary | null;
    onOpen: () => void;
}) {
    if (summary === null || summary.attempted === 0) {
        return <CellEmpty />;
    }

    return (
        <button
            type="button"
            className="flex cursor-pointer flex-col items-start gap-0.5 text-left"
            onClick={onOpen}
            title="Ver detalle de entregas"
        >
            <span className="flex items-center gap-1 text-2xs text-fg-2 hover:text-fg-1">
                <ListChecks size={11} aria-hidden="true" />
                {summary.delivered}/{summary.attempted} entregadas
            </span>
            {summary.failed > 0 && (
                <StatusBadge
                    size="sm"
                    tone="critical"
                    label={`${summary.failed} ${summary.failed === 1 ? 'falla' : 'fallas'}`}
                />
            )}
        </button>
    );
}

interface NotificationsTableProps {
    rows: NotificationRow[];
    onMarkRead: (id: number) => void;
    onOpenSource: (url: string) => void;
    onOpenDetail: (url: string) => void;
    empty?: React.ReactNode;
}

export function NotificationsTable({
    rows,
    onMarkRead,
    onOpenSource,
    onOpenDetail,
    empty,
}: NotificationsTableProps) {
    const columns = React.useMemo<DataTableColumn<NotificationRow>[]>(
        () => [
            {
                key: 'notification',
                header: 'Notificación',
                // Takes the leftover width and truncates inside it instead of
                // pushing the action column out of the viewport (auto table
                // layout lets a long subject grow the column otherwise).
                width: 'w-full max-w-0',
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
                                {notification.addressedToMe &&
                                    !notification.isRead && (
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
                                        notification.addressedToMe &&
                                            !notification.isRead &&
                                            'font-semibold',
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
                    <StatusBadge
                        size="sm"
                        {...notificationPriority(notification.priority)}
                        className="tracking-label"
                    />
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
                key: 'deliveries',
                header: 'Entregas',
                width: 'w-32',
                sortValue: (notification) =>
                    notification.deliverySummary?.failed ?? 0,
                cell: (notification) => (
                    <DeliveryCell
                        summary={notification.deliverySummary}
                        onOpen={() => onOpenDetail(notification.detailUrl)}
                    />
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
                sortValue: (notification) => notification.statusLabel,
                cell: (notification) => (
                    <StatusCell
                        label={notification.statusLabel}
                        tone={notification.statusTone}
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
                width: 'w-52 whitespace-nowrap',
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
                        {notification.addressedToMe && !notification.isRead && (
                            <button
                                type="button"
                                className="flex cursor-pointer items-center gap-1 rounded-sm border border-border px-2 py-1 text-2xs text-fg-2 transition-colors hover:border-border-strong hover:text-fg-1"
                                onClick={() => onMarkRead(notification.id)}
                            >
                                <Check size={11} />
                                Marcar leída
                            </button>
                        )}
                    </span>
                ),
            },
        ],
        [onMarkRead, onOpenSource, onOpenDetail],
    );

    // Delivery columns only earn their space once some notification on the
    // page actually went through a channel / resolved recipients.
    const visible = React.useMemo(
        () =>
            columns.filter((column) => {
                if (column.key === 'channels') {
                    return rows.some((n) => (n.channels ?? []).length > 0);
                }

                if (column.key === 'deliveries') {
                    return rows.some(
                        (n) => (n.deliverySummary?.attempted ?? 0) > 0,
                    );
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
