export interface AuditLogRow {
    id: number;
    action: string;
    actionLabel: string;
    category: string | null;
    categoryLabel: string | null;
    actorType: string | null;
    actorId: number | null;
    actorLabel: string | null;
    entityType: string | null;
    entityId: number | null;
    entityLabel: string | null;
    occurredAt: string | null;
}

export interface DomainEventRow {
    id: number;
    eventName: string;
    aggregateType: string | null;
    aggregateId: number | null;
    correlationId: string | null;
    occurredAt: string | null;
}

export interface AuditFilters {
    q: string | null;
    category: string | null;
    actor_type: string | null;
    from: string | null;
    to: string | null;
    system: boolean;
}

export interface FilterOption {
    value: string;
    label: string;
}

export interface AuditFilterOptions {
    categories: FilterOption[];
    actorTypes: FilterOption[];
}
