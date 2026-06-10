import type {
    AiDecision,
    IntegrationHealth,
    MockIncident,
    Severity,
} from './sam';

/** Inbox-row shape produced by IncidentInboxPresenter::toRow. */
export type IncidentRow = MockIncident;

export interface DashboardKpis {
    openIncidents: {
        value: number;
        deltaPct: number | null;
        series: number[];
    };
    criticalOpen: {
        value: number;
        avgSlaRemainingSeconds: number | null;
        series: number[];
    };
    slaCompliance: {
        value: number | null;
        deltaPp: number | null;
    };
    aiPrecision: {
        value: number | null;
        deltaPp: number | null;
    };
}

export interface DashboardStreamEvent {
    id: number;
    ts: string;
    provider: string;
    type: string;
    asset: string;
    decision: AiDecision;
    severity: Severity | null;
}

export interface DashboardIntegration {
    id: number;
    key: string;
    name: string;
    health: IntegrationHealth;
    events24h: number;
    lastSync: string | null;
}

export interface UsageCounterRow {
    meterCode: string;
    meterName: string;
    unit: string;
    consumed: number;
    included: number;
    overage: number;
    percentUsed: number | null;
    periodEnd: string | null;
}

export interface DashboardProps {
    kpis: DashboardKpis;
    incidents: IncidentRow[];
    stream: DashboardStreamEvent[];
    integrations: DashboardIntegration[];
    usage: UsageCounterRow[];
}
