import type { ToneLabel } from '@/lib/tone';

export type DemoRequestStatus = 'new' | 'contacted' | 'closed';

export const DEMO_REQUEST_STATUS: Record<DemoRequestStatus, ToneLabel> = {
    new: { label: 'Nueva', tone: 'critical' },
    contacted: { label: 'Contactada', tone: 'info' },
    closed: { label: 'Cerrada', tone: 'neutral' },
};

export const DEMO_REQUEST_STATUSES: DemoRequestStatus[] = [
    'new',
    'contacted',
    'closed',
];
