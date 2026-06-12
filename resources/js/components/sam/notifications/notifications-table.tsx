import { Check, ExternalLink } from 'lucide-react';
import * as React from 'react';
import { CellEmpty, DataTable } from '@/components/sam/data-table';
import type { DataTableColumn } from '@/components/sam/data-table';
import { RelativeTime } from '@/components/sam/relative-time';
import { cn } from '@/lib/utils';
import type {
    NotificationPriorityValue,
    NotificationRow,
    NotificationStatusValue,
} from '@/types/notifications';

function minutesSince(iso: string): number {
    return Math.max(0, Math.floor((Date.now() - Date.parse(iso)) / 60000));
}

const PRIORITY_STYLES: Record<NotificationPriorityValue, string> = {
    low: 'bg-surface-3 text-fg-2',
    normal: 'bg-surface-3 text-fg-2',
    high: 'bg-severity-medium/15 text-severity-medium',
    critical: 'bg-severity-critical/15 text-severity-critical',
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

function PriorityBadge({ priority }: { priority: NotificationPriorityValue }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2 py-0.5 text-3xs font-semibold',
                PRIORITY_STYLES[priority],
            )}
        >
            {PRIORITY_LABELS[priority]}
        </span>
    );
}

function StatusCell({ status }: { status: NotificationStatusValue }) {
    return (
        <span
            className={cn(
                'text-2xs',
                status === 'failed'
                    ? 'font-medium text-severity-critical'
                    : 'text-fg-2',
            )}
        >
            {STATUS_LABELS[status]}
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
                key: 'unread',
                header: '',
                width: 'w-8',
                cell: (notification) =>
                    !notification.isRead ? (
                        <span
                            className="inline-block size-2 rounded-full bg-primary"
                            aria-label="No leída"
                        />
                    ) : null,
            },
            {
                key: 'notification',
                header: 'Notificación',
                sortValue: (notification) =>
                    notification.subject ?? notification.type,
                cell: (notification) => (
                    <div className="flex flex-col">
                        <span
                            className={cn(
                                'truncate text-sm text-fg-1',
                                !notification.isRead && 'font-semibold',
                            )}
                        >
                            {notification.subject ?? notification.type}
                        </span>
                        {notification.bodyPreview && (
                            <span className="line-clamp-1 text-2xs text-fg-3">
                                {notification.bodyPreview}
                            </span>
                        )}
                        <span className="font-mono text-3xs text-fg-3">
                            {notification.type}
                        </span>
                    </div>
                ),
            },
            {
                key: 'priority',
                header: 'Prioridad',
                width: 'w-24',
                sortValue: (notification) =>
                    PRIORITY_RANK[notification.priority],
                cell: (notification) => (
                    <PriorityBadge priority={notification.priority} />
                ),
            },
            {
                key: 'status',
                header: 'Estado',
                width: 'w-24',
                sortValue: (notification) => STATUS_LABELS[notification.status],
                cell: (notification) => (
                    <StatusCell status={notification.status} />
                ),
            },
            {
                key: 'source',
                header: 'Fuente',
                width: 'w-28',
                cell: (notification) =>
                    notification.sourceUrl ? (
                        <button
                            type="button"
                            className="flex cursor-pointer items-center gap-1 text-2xs text-primary hover:underline"
                            onClick={() =>
                                onOpenSource(notification.sourceUrl as string)
                            }
                        >
                            <ExternalLink size={11} />
                            Ver incidente
                        </button>
                    ) : (
                        <span className="text-2xs text-fg-3">
                            {notification.sourceType}
                        </span>
                    ),
            },
            {
                key: 'date',
                header: 'Fecha',
                width: 'w-28',
                sortValue: (notification) => {
                    const iso = notification.sentAt ?? notification.createdAt;

                    return iso ? Date.parse(iso) : null;
                },
                cell: (notification) =>
                    (notification.sentAt ?? notification.createdAt) ? (
                        <RelativeTime
                            minutes={minutesSince(
                                (notification.sentAt ??
                                    notification.createdAt) as string,
                            )}
                        />
                    ) : (
                        <CellEmpty />
                    ),
            },
            {
                key: 'action',
                header: 'Acción',
                width: 'w-32',
                cell: (notification) =>
                    !notification.isRead ? (
                        <button
                            type="button"
                            className="flex cursor-pointer items-center gap-1 rounded-sm border border-border px-2 py-1 text-2xs text-fg-2 transition-colors hover:border-border-strong hover:text-fg-1"
                            onClick={() => onMarkRead(notification.id)}
                        >
                            <Check size={11} />
                            Marcar leída
                        </button>
                    ) : null,
            },
        ],
        [onMarkRead, onOpenSource],
    );

    return (
        <DataTable
            columns={columns}
            rows={rows}
            rowKey={(notification) => notification.id}
            density="relaxed"
            empty={empty}
        />
    );
}
