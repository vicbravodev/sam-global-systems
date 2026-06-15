export type AssetStatusValue =
    | 'active'
    | 'inactive'
    | 'offline'
    | 'alert'
    | 'critical'
    | 'maintenance';

export interface AssetTypeSummary {
    code: string;
    name: string;
    category: string;
}

export interface AssetDeviceSummary {
    id: number;
    deviceType: string;
    externalDeviceId: string | null;
    status: string;
}

export interface AssetLocationSummary {
    latitude: number;
    longitude: number;
    formattedLocation: string | null;
    speed: number | null;
    heading: number | null;
    recordedAt: string;
}

export interface AssetRow {
    id: number;
    name: string;
    code: string | null;
    status: AssetStatusValue;
    type: AssetTypeSummary | null;
    devices: AssetDeviceSummary[];
    lastLocation: AssetLocationSummary | null;
    /** Inventory-sync timestamp (bumps in bulk; NOT a real signal). */
    lastSeenAt: string | null;
    /** Latest REAL signal: newest location or telemetry snapshot. */
    lastSignalAt: string | null;
}

export interface AssetFilters {
    q: string | null;
    status: string | null;
    type: string | null;
}

export interface AssetFilterOptions {
    statuses: { value: string; label: string }[];
    types: { value: string; label: string }[];
}

export interface AssetsPagination {
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
}

export interface AssetsIndexProps {
    assets: AssetRow[];
    pagination: AssetsPagination;
    filters: AssetFilters;
    filterOptions: AssetFilterOptions;
}

export interface AssetDriverSummary {
    id: number;
    name: string;
    employeeCode: string | null;
}

export interface AssetDetail extends AssetRow {
    externalPrimaryId: string | null;
    provider: string | null;
    sourceIntegration: string | null;
    firstSeenAt: string | null;
    /** Currently assigned primary driver, null when none (C-08). */
    driver: AssetDriverSummary | null;
}

export interface TelemetryEntry {
    type: string;
    label: string;
    data: Record<string, unknown> | null;
    recordedAt: string;
}

export interface LocationHistoryEntry {
    id: number;
    latitude: number;
    longitude: number;
    formattedLocation: string | null;
    speed: number | null;
    heading: number | null;
    source: string;
    recordedAt: string;
}

export interface LinkedIncident {
    id: number;
    title: string;
    status: { code: string; name: string } | null;
    priority: { code: string; name: string } | null;
    type: string | null;
    openedAt: string | null;
}

export interface AssetShowProps {
    asset: AssetDetail;
    telemetry: TelemetryEntry[];
    locationHistory: LocationHistoryEntry[];
    incidents: LinkedIncident[];
}

export interface AssetMarker {
    id: number;
    name: string;
    code: string | null;
    status: AssetStatusValue;
    category: string | null;
    latitude: number;
    longitude: number;
    speed: number | null;
    heading: number | null;
    recordedAt: string;
}

export interface AssetsMapProps {
    assets: AssetMarker[];
    unpositionedCount: number;
    statusLabels: Record<string, string>;
}
