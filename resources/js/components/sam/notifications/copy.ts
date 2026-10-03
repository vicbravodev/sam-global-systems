import { priorityLabel } from '@/lib/labels';
import type { Tone, ToneLabel } from '@/lib/tone';
import type {
    DeliveryTone,
    NotificationPriorityValue,
    NotificationStatusTone,
} from '@/types/notifications';

/** Prioridad de una notificación: etiqueta (de `lib/labels`) y tono. */
export function notificationPriority(
    priority: NotificationPriorityValue,
): ToneLabel {
    return {
        label: priorityLabel(priority),
        tone:
            priority === 'critical'
                ? 'critical'
                : priority === 'high'
                  ? 'warn'
                  : 'neutral',
    };
}

/** Tono del estado que calcula el backend (`NotificationPresenter`). */
export const NOTIFICATION_STATUS_TONE: Record<NotificationStatusTone, Tone> = {
    ok: 'ok',
    warning: 'warn',
    critical: 'critical',
    info: 'info',
    muted: 'neutral',
    neutral: 'neutral',
};

/** Tono del estado de una entrega individual (`DeliveryPresenter`). */
export const DELIVERY_TONE: Record<DeliveryTone, Tone> = {
    success: 'ok',
    danger: 'critical',
    muted: 'neutral',
    pending: 'warn',
};

/** Estado de entrega de un canal dentro de una notificación (minúsculas: va tras "Canal: "). */
export const CHANNEL_DELIVERY: Record<string, ToneLabel> = {
    pending: { label: 'pendiente', tone: 'neutral' },
    queued: { label: 'en cola', tone: 'neutral' },
    sending: { label: 'enviando', tone: 'neutral' },
    sent: { label: 'enviado al operador', tone: 'neutral' },
    delivered: { label: 'entregado', tone: 'ok' },
    failed: { label: 'falló', tone: 'critical' },
    bounced: { label: 'rebotó', tone: 'critical' },
    retrying: { label: 'reintentando', tone: 'warn' },
    cancelled: { label: 'cancelado', tone: 'neutral' },
    skipped: { label: 'omitido (sin contacto)', tone: 'neutral' },
};

/** Entregas que no se intentaron: se dibujan con borde punteado. */
export const UNATTEMPTED_DELIVERY = new Set(['cancelled', 'skipped']);
