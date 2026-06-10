import { Check, ExternalLink } from 'lucide-react';
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
                'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold',
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
                'text-[11px]',
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
}

export function NotificationsTable({
    rows,
    onMarkRead,
    onOpenSource,
}: NotificationsTableProps) {
    return (
        <div className="min-h-0 flex-1 overflow-auto">
            <table className="w-full border-collapse">
                <thead>
                    <tr className="sticky top-0 z-10 border-b border-border bg-surface-3 text-[10px] font-semibold tracking-[0.08em] text-fg-3 uppercase">
                        <th className="w-8 px-2.5 py-2" />
                        <th className="px-2.5 py-2 text-left">Notificación</th>
                        <th className="w-24 px-2.5 py-2 text-left">
                            Prioridad
                        </th>
                        <th className="w-24 px-2.5 py-2 text-left">Estado</th>
                        <th className="w-28 px-2.5 py-2 text-left">Fuente</th>
                        <th className="w-28 px-2.5 py-2 text-left">Fecha</th>
                        <th className="w-32 px-2.5 py-2 text-left">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((notification) => (
                        <tr
                            key={notification.id}
                            className={cn(
                                'border-b border-border transition-colors hover:bg-surface-2',
                                !notification.isRead && 'bg-primary/[0.03]',
                            )}
                        >
                            <td className="px-2.5 py-2.5 text-center">
                                {!notification.isRead && (
                                    <span
                                        className="inline-block size-2 rounded-full bg-primary"
                                        aria-label="No leída"
                                    />
                                )}
                            </td>
                            <td className="px-2.5 py-2.5">
                                <div className="flex flex-col">
                                    <span
                                        className={cn(
                                            'truncate text-[13px] text-fg-1',
                                            !notification.isRead &&
                                                'font-semibold',
                                        )}
                                    >
                                        {notification.subject ??
                                            notification.type}
                                    </span>
                                    {notification.bodyPreview && (
                                        <span className="line-clamp-1 text-[11px] text-fg-3">
                                            {notification.bodyPreview}
                                        </span>
                                    )}
                                    <span className="font-mono text-[10px] text-fg-3">
                                        {notification.type}
                                    </span>
                                </div>
                            </td>
                            <td className="px-2.5 py-2.5">
                                <PriorityBadge
                                    priority={notification.priority}
                                />
                            </td>
                            <td className="px-2.5 py-2.5">
                                <StatusCell status={notification.status} />
                            </td>
                            <td className="px-2.5 py-2.5">
                                {notification.sourceUrl ? (
                                    <button
                                        type="button"
                                        className="flex cursor-pointer items-center gap-1 text-[11px] text-primary hover:underline"
                                        onClick={() =>
                                            onOpenSource(
                                                notification.sourceUrl as string,
                                            )
                                        }
                                    >
                                        <ExternalLink size={11} />
                                        Ver incidente
                                    </button>
                                ) : (
                                    <span className="text-[11px] text-fg-3">
                                        {notification.sourceType}
                                    </span>
                                )}
                            </td>
                            <td className="px-2.5 py-2.5">
                                {(notification.sentAt ??
                                notification.createdAt) ? (
                                    <RelativeTime
                                        minutes={minutesSince(
                                            (notification.sentAt ??
                                                notification.createdAt) as string,
                                        )}
                                    />
                                ) : (
                                    <span className="text-fg-3">—</span>
                                )}
                            </td>
                            <td className="px-2.5 py-2.5">
                                {!notification.isRead && (
                                    <button
                                        type="button"
                                        className="flex cursor-pointer items-center gap-1 rounded-sm border border-border px-2 py-1 text-[11px] text-fg-2 transition-colors hover:border-border-strong hover:text-fg-1"
                                        onClick={() =>
                                            onMarkRead(notification.id)
                                        }
                                    >
                                        <Check size={11} />
                                        Marcar leída
                                    </button>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
