import type { LinkedIncidentEntry } from '@/components/sam/linked-incidents-card';
import type { RecentEventEntry } from '@/components/sam/recent-events-card';

export type AssetStatusValue =
    | 'active'
    | 'inactive'
    | 'offline'
    | 'alert'
    | 'critical'
    | 'maintenance';

/** Si SAM vigila (y cobra) la unidad. Sólo `monitored` entra al pipeline. */
export type AssetMonitoringState = 'monitored' | 'pending' | 'excluded';

export interface AssetTypeSummary {
    code: string;
    name: string;
    category: string;
}

export interface AssetDeviceSummary {
    id: number;
    deviceType: string;
    /** Spanish label for `deviceType`; falls back to the raw code. */
    label: string;
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

/** Vehicle facts synced from the provider (Samsara: make/model/year, plate, VIN). */
export interface AssetVehicleFacts {
    make: string | null;
    model: string | null;
    year: number | null;
    plate: string | null;
    vin: string | null;
    hasCamera: boolean;
}

export interface AssetDriverSummary {
    id: number;
    name: string;
    employeeCode: string | null;
}

/**
 * The one current speed every surface shows: newest of position and speed
 * telemetry. `stale` = older than the reporting window (server-computed,
 * same rule as the header "En ruta" count).
 */
export interface AssetCurrentSpeed {
    kph: number;
    recordedAt: string;
    source: 'location' | 'telemetry';
    stale: boolean;
}

export interface AssetRow {
    id: number;
    name: string;
    code: string | null;
    status: AssetStatusValue;
    monitoringState: AssetMonitoringState;
    type: AssetTypeSummary | null;
    vehicle: AssetVehicleFacts | null;
    /** Currently assigned primary driver, null when none. */
    driver: AssetDriverSummary | null;
    devices: AssetDeviceSummary[];
    lastLocation: AssetLocationSummary | null;
    currentSpeed: AssetCurrentSpeed | null;
    /** Inventory-sync timestamp (bumps in bulk; NOT a real signal). */
    lastSeenAt: string | null;
    /** Latest REAL signal: newest location or telemetry snapshot. */
    lastSignalAt: string | null;
}

export interface AssetFilters {
    q: string | null;
    status: string | null;
    type: string | null;
    monitoring: string | null;
}

export interface AssetFilterOptions {
    statuses: { value: string; label: string }[];
    types: { value: string; label: string }[];
    monitoring: { value: string; label: string }[];
}

/** Cupo de vigilancia del tenant. Tope suave: `overCap` sólo avisa. */
export interface MonitoringSummary {
    monitored: number;
    pending: number;
    excluded: number;
    cap: number | null;
    overCap: boolean;
}

export interface AssetsPagination {
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
}

/** Pulso de toda la flota del tenant (ignora filtros). */
export interface AssetsSummary {
    total: number;
    statuses: Record<AssetStatusValue, number>;
    reporting: number;
    moving: number;
    silent: number;
    alerting: number;
    maintenance: number;
    withCamera: number;
}

export interface AssetsIndexProps {
    assets: AssetRow[];
    pagination: AssetsPagination;
    filters: AssetFilters;
    filterOptions: AssetFilterOptions;
    summary?: AssetsSummary;
    monitoring?: MonitoringSummary;
}

export interface AssetDetail extends AssetRow {
    externalPrimaryId: string | null;
    provider: string | null;
    sourceIntegration: string | null;
    firstSeenAt: string | null;
}

export interface TelemetryEntry {
    type: string;
    label: string;
    data: Record<string, unknown> | null;
    recordedAt: string;
    /** Only on `speed`: the reading is older than the reporting window. */
    stale?: boolean;
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

export type LinkedIncident = LinkedIncidentEntry;

export interface AssetShowProps {
    asset: AssetDetail;
    telemetry: TelemetryEntry[];
    locationHistory: LocationHistoryEntry[];
    incidents: LinkedIncident[];
    recentEvents: RecentEventEntry[];
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
