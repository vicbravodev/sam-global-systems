export type DriverStatusValue =
    | 'active'
    | 'off_duty'
    | 'unavailable'
    | 'suspended'
    | 'under_review';

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

export interface DriversPagination {
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
}

export interface DriversIndexProps {
    drivers: DriverRow[];
    pagination: DriversPagination;
    filters: DriverFilters;
    filterOptions: DriverFilterOptions;
    columns?: DriverColumnPresence;
}

export interface DriverRiskProfile {
    riskScore: number | null;
    riskLevel: 'low' | 'medium' | 'high' | 'critical' | null;
    incidentsCount: number;
    harshEventsCount: number;
    fatigueFlagsCount: number;
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
}

export interface DriverDetail {
    id: number;
    fullName: string;
    firstName: string | null;
    lastName: string | null;
    employeeCode: string | null;
    externalPrimaryId: string | null;
    status: DriverStatusValue;
    firstSeenAt: string | null;
    lastSeenAt: string | null;
    currentAsset: DriverAssetSummary | null;
    riskProfile: DriverRiskProfile | null;
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

export interface DriverStatusLogEntry {
    id: number;
    statusCode: string;
    statusLabel: string | null;
    severity: 'low' | 'medium' | 'high' | 'critical' | null;
    effectiveFrom: string | null;
    effectiveTo: string | null;
}

export interface DriverShowProps {
    driver: DriverDetail;
    assignments: DriverAssignmentEntry[];
    statusLog: DriverStatusLogEntry[];
}
