import type { IncidentStatus, Severity } from '@/components/sam';

export type { Severity, IncidentStatus };

export type IntegrationHealth = 'ok' | 'warn' | 'down' | 'unknown';

export type AiDecision = 'incident' | 'escalate' | 'info' | 'discard';

export interface MockAssignee {
    name: string;
    initials: string;
}

export interface MockIncident {
    id: string;
    incidentId: number;
    title: string;
    severity: Severity;
    status: IncidentStatus;
    provider: string;
    asset: string;
    driver: string;
    assignee: MockAssignee | null;
    slaSeconds: number;
    slaTotal: number;
    ageMin: number;
    eventType: string;
    location: string;
    aiConfidence: number;
    aiDecision: AiDecision;
    aiReason: string;
    realtime?: boolean;
}

export interface MockIntegration {
    name: string;
    key: string;
    health: IntegrationHealth;
    events24h: number;
    lastSync: string;
}

export interface MockStreamEvent {
    ts: string;
    provider: string;
    type: string;
    asset: string;
    decision: AiDecision;
    severity: Severity | null;
}

export interface DashboardMockData {
    incidents: MockIncident[];
    integrations: MockIntegration[];
    stream: MockStreamEvent[];
}

// ---- Inbox UI state types ----

export type InboxLayout = 'table' | 'grouped' | 'stream';
export type InboxDensity = 'compact' | 'comfortable' | 'relaxed';
export type InboxTab =
    | 'open'
    | 'mine'
    | 'unassigned'
    | 'sla'
    | 'all'
    | 'discarded';

// ---- Inbox filters & action option lists (server-provided) ----

export interface InboxFilters {
    q: string | null;
    severity: string | null;
    status: string | null;
    provider: string | null;
    shift: string | null;
}

export interface InboxFilterOption {
    value: string;
    label: string;
}

export interface InboxFilterOptions {
    severities: InboxFilterOption[];
    statuses: InboxFilterOption[];
    providers: string[];
    shifts: InboxFilterOption[];
}

export interface InboxMember {
    id: number;
    name: string;
}

export interface ReclassifyOption {
    id: number;
    code: string;
    name: string;
}

export interface ReclassifyOptions {
    types: ReclassifyOption[];
    priorities: ReclassifyOption[];
}

// ---- Nav badges (sidebar) ----

export interface NavBadges {
    inbox: number;
    rules: number;
    integrations: number;
}

// ---- Timeline entries for detail panel ----

export type TimelineEntryType =
    | 'system'
    | 'webhook'
    | 'ai'
    | 'user'
    | 'critical'
    | 'assign'
    | 'comment';

export interface IncidentTimelineEntry {
    type: TimelineEntryType;
    actor: string;
    text: string;
    ts: string;
    sub?: string;
}

// ---- Related incident link ----

export interface RelatedIncidentLink {
    ts: string;
    eventType: string;
    asset: string;
    decision: AiDecision;
    severity: Severity | null;
}

// ---- Comment ----

export interface IncidentComment {
    authorInitials: string;
    authorName: string;
    visibility: 'internal' | 'tenant' | 'audit';
    body: string;
    relativeTime: string;
}

// ---- Evidence item ----

export interface IncidentEvidenceItem {
    label: string;
    sub: string;
    type: 'chart' | 'video' | 'map' | 'payload';
}

// ---- Full incident detail (extends MockIncident) ----

export interface IncidentDetail extends MockIncident {
    aiEvaluationId: number | null;
    model: string;
    latencyMs: number;
    timeline: IncidentTimelineEntry[];
    relatedLinks: RelatedIncidentLink[];
    comments: IncidentComment[];
    evidence: IncidentEvidenceItem[];
    operationalContext: {
        weather: string;
        traffic: string;
        driverRisk: number;
        geofenceStatus: string;
        drivingHours: string;
    };
}

// ---- Inbox mock data (full dataset for the incidents page) ----

export interface InboxMockData {
    user: { name: string; initials: string; role: string };
    tenant: { slug: string; name: string; logoColor: string };
    navBadges: NavBadges;
    incidents: IncidentDetail[];
    integrations: MockIntegration[];
    stream: MockStreamEvent[];
}
