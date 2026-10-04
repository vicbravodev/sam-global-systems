import type { ListPagination } from '@/types/pagination';

export type EventPipelineStatus =
    | 'normalized'
    | 'enrichment_pending'
    | 'enriched'
    | 'failed'
    | 'unmapped';

export interface EventRow {
    id: number;
    occurredAt: string | null;
    status: EventPipelineStatus | null;
    statusLabel: string | null;
    eventType: string | null;
    eventTypeCode: string | null;
    category: string | null;
    categoryCode: string | null;
    severity: string | null;
    severityLabel: string | null;
    severityColor: string | null;
    asset: string | null;
    assetId: number | null;
    driver: string | null;
    driverId: number | null;
    provider: string | null;
    /** Provider description when it says more than the type name. */
    description: string | null;
    hasEvaluation: boolean;
    hasIncident: boolean;
}

export interface EventFilters {
    q: string | null;
    status: string | null;
    event_type_id: number | null;
    event_category_id: number | null;
    event_severity_id: number | null;
    occurred_from: string | null;
    occurred_until: string | null;
}

export interface EventFilterOption {
    value: string;
    label: string;
    /** Only severities carry their catalog code (for the colored dot). */
    code?: string;
}

export interface EventFilterOptions {
    eventTypes: EventFilterOption[];
    categories: EventFilterOption[];
    severities: EventFilterOption[];
    statuses: EventFilterOption[];
}

/** Pulso de eventos del tenant (ignora filtros). */
export interface EventsSummary {
    last24h: number;
    severe24h: number;
    incidents24h: number;
    unmapped: number;
    failed: number;
}

export interface EventsIndexProps {
    events: EventRow[];
    pagination: ListPagination;
    filters: EventFilters;
    filterOptions: EventFilterOptions;
    unmappedCount: number;
    summary?: EventsSummary;
}

export interface EventFacts {
    location: {
        latitude: number;
        longitude: number;
        formatted: string | null;
    } | null;
    labels: string[];
    externalEventType: string | null;
    externalUrl: string | null;
    isResolved: boolean | null;
    externalResolvedAt: string | null;
    eventState: string | null;
    /** Safety event del que esta alerta de Samsara es eco. */
    echoOfEventId: number | null;
}

export interface EventDetail extends EventRow {
    processedAt: string | null;
    payload: Record<string, unknown> | null;
    context: Record<string, unknown> | null;
    rawPayload: Record<string, unknown> | null;
    rawEventId: number | null;
    facts: EventFacts;
}

export interface EventEvaluation {
    id: number;
    version: number;
    /** Stand-in agent (`null-agent:*`): not a real verdict, no scores. */
    isPlaceholder: boolean;
    placeholderLabel: string | null;
    classification: string | null;
    classificationLabel: string | null;
    confidenceScore: number | null;
    riskScore: number | null;
    priorityLevel: string | null;
    mode: string | null;
    isRealEvent: boolean | null;
    requiresAction: boolean;
    recommendedAction: string | null;
    explanation: string | null;
    evaluatedAt: string | null;
}

export interface EventDecision {
    id: number;
    code: string | null;
    outcomeLabel: string | null;
    reason: string | null;
    requiresHumanReview: boolean;
    isAutomated: boolean;
    priorityLevel: string | null;
    decidedAt: string | null;
}

export interface EventIncident {
    id: number;
    reference: string;
    title: string;
    status: string | null;
    uiStatus: string;
    statusLabel: string;
    severity: string | null;
    openedAt: string | null;
}

export interface EventMediaItem {
    id: number;
    mediaType: string | null;
    mediaRole: string | null;
    mimeType: string | null;
    url: string | null;
    thumbnailUrl: string | null;
    capturedAt: string | null;
    durationSeconds: number | null;
}

export interface EventShowProps {
    event: EventDetail;
    evaluation: EventEvaluation | null;
    decision: EventDecision | null;
    incident: EventIncident | null;
    media: EventMediaItem[];
}
