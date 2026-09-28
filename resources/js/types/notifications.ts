export type NotificationPriorityValue = 'low' | 'normal' | 'high' | 'critical';

export type NotificationStatusValue =
    | 'pending'
    | 'queued'
    | 'partially_sent'
    | 'sent'
    | 'failed'
    | 'cancelled';

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
    /** Explicación humana de por qué no salió (solo estados cancelados). */
    statusReason: string | null;
    /** Resumen de entregas por canal (null en vistas sin conteos). */
    deliverySummary: NotificationDeliverySummary | null;
    detailUrl: string;
}

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

export interface NotificationsIndexProps {
    notifications: NotificationRow[];
    pagination: NotificationsPagination;
    filters: NotificationFilters;
    filterOptions: NotificationFilterOptions;
}
