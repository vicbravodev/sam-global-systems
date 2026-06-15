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

export interface NotificationsIndexProps {
    notifications: NotificationRow[];
    pagination: NotificationsPagination;
    filters: NotificationFilters;
    filterOptions: NotificationFilterOptions;
}
