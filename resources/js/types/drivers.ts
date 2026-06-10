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
}
