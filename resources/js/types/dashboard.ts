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
    /** ISO 8601; se formatea en el cliente. */
    occurredAt: string | null;
    provider: string;
    type: string;
    asset: string;
    decision: AiDecision;
    severity: Severity | null;
}

export interface DashboardIntegration {
    id: number;
    key: string;
    /** Nombre de la integración del tenant (no del proveedor). */
    name: string;
    provider: string;
    health: IntegrationHealth;
    events24h: number;
    lastSync: string | null;
}

export interface UsageCounterRow {
    meterCode: string;
    meterName: string;
    unit: string;
    /** Importe a cobrar (medidores cost-plus en usd_micros); null si no aplica. */
    amount: number | null;
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
