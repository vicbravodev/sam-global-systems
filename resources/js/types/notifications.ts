export type NotificationPriorityValue = 'low' | 'normal' | 'high' | 'critical';

export type NotificationStatusValue =
    | 'pending'
    | 'queued'
    | 'partially_sent'
    | 'sent'
    | 'failed'
    | 'cancelled';

export type NotificationChannelType =
    | 'email'
    | 'sms'
    | 'push'
    | 'whatsapp'
    | 'web'
    | 'slack'
    | 'webhook'
    | 'voice';

export type DeliveryStatusValue =
    | 'pending'
    | 'queued'
    | 'sending'
    | 'sent'
    | 'delivered'
    | 'failed'
    | 'bounced'
    | 'retrying'
    | 'cancelled'
    | 'skipped';

/** Un chip por canal con el peor estado de entrega visto en ese canal. */
export interface NotificationChannelSummary {
    type: NotificationChannelType | string;
    status: DeliveryStatusValue | string;
    count: number;
}

export interface NotificationRow {
    id: number;
    type: string;
    priority: NotificationPriorityValue;
    status: NotificationStatusValue;
    subject: string | null;
    bodyPreview: string | null;
    sourceType: string;
    sourceUrl: string | null;
    sentAt: string | null;
    createdAt: string | null;
    isRead: boolean;
    /** La notificación iba dirigida al usuario actual ("sin leer" es personal). */
    addressedToMe: boolean;
    /** Estado honesto según lo que hicieron las entregas (p.ej. "Parcial 1/3"). */
    statusLabel: string;
    statusTone: NotificationStatusTone;
    /** Explicación humana de por qué no salió (solo estados cancelados). */
    statusReason: string | null;
    recipientsCount: number;
    channels: NotificationChannelSummary[];
    /** Resumen de entregas por canal (null en vistas sin conteos). */
    deliverySummary: NotificationDeliverySummary | null;
    detailUrl: string;
}

export type NotificationStatusTone =
    | 'ok'
    | 'warning'
    | 'critical'
    | 'info'
    | 'muted'
    | 'neutral';

export interface NotificationDeliverySummary {
    /** Entregas intentadas (excluye omitidas/canceladas). */
    attempted: number;
    delivered: number;
    failed: number;
}

export type DeliveryTone = 'success' | 'danger' | 'muted' | 'pending';

export interface DeliveryProviderEvent {
    status: string;
    label: string;
    errorCode: string | null;
    at: string | null;
    source: string | null;
}

export interface NotificationDeliveryRow {
    id: number;
    recipient: { id: number; name: string | null; type: string | null };
    channel: { type: string | null; label: string | null };
    address: string | null;
    status: string;
    statusLabel: string;
    tone: DeliveryTone;
    reason: string | null;
    attempts: number;
    isFallback: boolean;
    /** Canal cuya falla abrió esta entrega alterna (null si no es fallback). */
    fallbackFromChannel: string | null;
    callDurationSeconds: number | null;
    acceptedAt: string | null;
    sentAt: string | null;
    deliveredAt: string | null;
    readAt: string | null;
    answeredAt: string | null;
    failedAt: string | null;
    events: DeliveryProviderEvent[];
}

export interface NotificationShowProps {
    notification: NotificationRow;
    deliveries: NotificationDeliveryRow[];
}

export interface NotificationFilters {
    status: string | null;
    priority: string | null;
    unread: boolean;
    /** Solo notificaciones con alguna entrega fallida. */
    failures: boolean;
}

export interface NotificationFilterOptions {
    statuses: { value: string; label: string }[];
    priorities: { value: string; label: string }[];
}

export interface NotificationsPagination {
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
}

/** Pulso del centro de notificaciones (ignora filtros). */
export interface NotificationsSummary {
    unread: number;
    sent24h: number;
    undelivered24h: number;
    critical24h: number;
}

export interface NotificationsIndexProps {
    notifications: NotificationRow[];
    pagination: NotificationsPagination;
    filters: NotificationFilters;
    filterOptions: NotificationFilterOptions;
    summary?: NotificationsSummary;
}
