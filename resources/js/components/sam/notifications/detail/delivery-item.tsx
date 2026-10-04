import {
    Bell,
    Globe,
    Mail,
    MessageCircle,
    MessageSquare,
    Phone,
    Send,
    Smartphone,
    Truck,
    Webhook,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { MetaChip } from '@/components/sam/meta-chip';
import { DELIVERY_TONE } from '@/components/sam/notifications/copy';
import { StatusBadge } from '@/components/sam/status-badge';
import { formatDateTime } from '@/lib/format';
import type { NotificationDeliveryRow } from '@/types/notifications';

const CHANNEL_ICONS: Record<string, LucideIcon> = {
    email: Mail,
    sms: MessageSquare,
    whatsapp: MessageCircle,
    voice: Phone,
    push: Smartphone,
    samsara_driver_app: Truck,
    web: Globe,
    slack: Send,
    webhook: Webhook,
};

function DeliveryStatus({ delivery }: { delivery: NotificationDeliveryRow }) {
    return (
        <div className="flex flex-col items-start gap-1">
            <StatusBadge
                size="sm"
                tone={DELIVERY_TONE[delivery.tone]}
                label={delivery.statusLabel}
            />
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

export function DeliveryItem({
    delivery,
}: {
    delivery: NotificationDeliveryRow;
}) {
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
                {delivery.isFallback && (
                    <MetaChip>
                        {delivery.fallbackFromChannel
                            ? `Canal alterno tras ${delivery.fallbackFromChannel}`
                            : 'Canal alterno'}
                    </MetaChip>
                )}
                {delivery.attempts > 1 && (
                    <MetaChip>{delivery.attempts} intentos</MetaChip>
                )}
            </div>
        </li>
    );
}
