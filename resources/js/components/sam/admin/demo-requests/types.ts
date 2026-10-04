import type { DemoRequestStatus } from './copy';

export interface DemoRequestRow {
    id: number;
    name: string;
    company: string;
    email: string;
    phone: string | null;
    fleetSize: string;
    message: string | null;
    status: DemoRequestStatus;
    handledBy: string | null;
    statusChangedAt: string | null;
    notified: boolean;
    createdAt: string | null;
}
