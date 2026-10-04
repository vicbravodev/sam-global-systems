import type { LinkedIncidentEntry } from '@/components/sam/linked-incidents-card';
import type { RecentEventEntry } from '@/components/sam/recent-events-card';
import type { HosDriverPanelData } from '@/types/hos';
import type { ListPagination } from '@/types/pagination';

export type DriverStatusValue =
    | 'active'
    | 'off_duty'
    | 'unavailable'
    | 'suspended'
    | 'under_review';

export type DriverRiskLevel = 'low' | 'medium' | 'high' | 'critical';

export type DriverRiskTrend =
    | 'baseline'
    | 'improving'
    | 'deteriorating'
    | 'stable';

export interface DriverAssetSummary {
    id: number;
    name: string;
    code: string | null;
}

export interface DriverRow {
    id: number;
    fullName: string;
    employeeCode: string | null;
    status: DriverStatusValue;
    currentAsset: DriverAssetSummary | null;
    riskScore: number | null;
    riskLevel: DriverRiskLevel | null;
    riskTrend: DriverRiskTrend | null;
    incidentsCount: number;
    harshEventsCount: number;
    phone: string | null;
    lastSeenAt: string | null;
}

export interface DriverFilters {
    q: string | null;
    status: string | null;
}

export interface DriverFilterOptions {
    statuses: { value: string; label: string }[];
}

/**
 * Qué columnas opcionales del roster tienen al menos un dato en TODO el
 * tenant (lo calcula el backend); una columna vacía para toda la flota se
 * oculta en vez de pintar "—" en cada fila.
 */
export interface DriverColumnPresence {
    asset: boolean;
    risk: boolean;
    phone: boolean;
    lastSeen: boolean;
}

/** Pulso del roster completo del tenant (ignora filtros). */
export interface DriversSummary {
    total: number;
    statuses: Record<DriverStatusValue, number>;
    highRisk: number;
    unassigned: number;
    seenToday: number;
}

export interface DriversIndexProps {
    drivers: DriverRow[];
    pagination: ListPagination;
    filters: DriverFilters;
    filterOptions: DriverFilterOptions;
    columns?: DriverColumnPresence;
    summary?: DriversSummary;
}

export interface DriverRiskProfile {
    riskScore: number | null;
    riskLevel: DriverRiskLevel | null;
    trend: DriverRiskTrend | null;
    previousScore: number | null;
    windowDays: number | null;
    incidentsCount: number;
    harshEventsCount: number;
    fatigueFlagsCount: number;
    severeEventsCount: number;
    lastCalculatedAt: string | null;
}

export interface DriverContactEntry {
    id: number;
    contactType: string;
    label: string | null;
    value: string;
    isPrimary: boolean;
    isEmergency: boolean;
    verifiedAt: string | null;
}

export interface DriverDocumentEntry {
    id: number;
    documentType: string;
    documentNumber: string | null;
    status: string;
    issuedAt: string | null;
    expiresAt: string | null;
    fileUrl: string | null;
    isExpired: boolean;
    /** Días hasta el vencimiento (negativo si ya venció); null sin fecha. */
    daysToExpiry: number | null;
}

/** Campo del perfil sincronizado desde el proveedor (licencia, usuario…). */
export interface DriverProviderField {
    key: string;
    label: string;
    value: string;
}

export interface DriverDetail {
    id: number;
    fullName: string;
    firstName: string | null;
    lastName: string | null;
    employeeCode: string | null;
    externalPrimaryId: string | null;
    phone: string | null;
    status: DriverStatusValue;
    firstSeenAt: string | null;
    lastSeenAt: string | null;
    /** Latest real activity: driver event or current unit's signal. */
    lastSignalAt: string | null;
    currentAsset: DriverAssetSummary | null;
    riskProfile: DriverRiskProfile | null;
    providerFields: DriverProviderField[];
    contacts: DriverContactEntry[];
    documents: DriverDocumentEntry[];
}

export interface DriverAssignmentEntry {
    id: number;
    asset: DriverAssetSummary | null;
    assignmentType: string;
    source: string;
    startedAt: string | null;
    endedAt: string | null;
    isCurrent: boolean;
}

export interface DriverActivityPoint {
    date: string;
    count: number;
}

export interface DriverShowProps {
    driver: DriverDetail;
    assignments: DriverAssignmentEntry[];
    recentEvents: RecentEventEntry[];
    incidents: LinkedIncidentEntry[];
    activity: DriverActivityPoint[];
    /** Pestaña HOS; null sin la feature `hos_monitoring`. */
    hos: HosDriverPanelData | null;
}
