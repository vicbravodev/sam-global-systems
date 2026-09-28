export type MetricGroup = 'incidents' | 'ai' | 'decisions' | 'usage';

export interface MetricPoint {
    date: string;
    value: number;
}

export interface MetricRow {
    code: string;
    /** Nombre del catálogo metric_definitions (es), si existe. */
    name: string | null;
    group: MetricGroup;
    unit: string | null;
    /** sum = actividad del periodo, avg = promedio ponderado, latest = nivel actual. */
    aggregation: 'sum' | 'avg' | 'latest';
    value: number | null;
    /** Mismo cálculo sobre el periodo anterior de igual duración. */
    previous: number | null;
    series: MetricPoint[];
}

export interface AnalyticsRange {
    from: string;
    to: string;
    previousFrom: string;
    previousTo: string;
}

export interface FleetLevel {
    monitored: number;
    total: number;
}

export interface ReportRow {
    id: number;
    code: string;
    name: string;
    description: string | null;
    reportType: string;
    /** daily | weekly | monthly cuando se genera solo; null si es a pedido. */
    frequency: string | null;
}

export interface ExecutionRow {
    id: number;
    reportId: number | null;
    reportName: string | null;
    status: string | null;
    format: string;
    error: string | null;
    requestedAt: string | null;
    finishedAt: string | null;
    automatic: boolean;
    downloadable: boolean;
}

export interface AnalyticsPageProps {
    period: number;
    periods: number[];
    range: AnalyticsRange;
    fleet: FleetLevel;
    metrics: MetricRow[];
    reports: ReportRow[];
    executions: ExecutionRow[];
    formats: string[];
    canGenerate: boolean;
}
