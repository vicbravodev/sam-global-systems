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
    lastSeenAt: string | null;
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
