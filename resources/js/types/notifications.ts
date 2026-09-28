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
    /** Explicación humana de por qué no salió (solo estados cancelados). */
    statusReason: string | null;
    recipientsCount: number;
    channels: NotificationChannelSummary[];
}

export interface NotificationFilters {
    status: string | null;
    priority: string | null;
    unread: boolean;
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
