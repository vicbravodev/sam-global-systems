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
