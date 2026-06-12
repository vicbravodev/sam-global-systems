import { cn } from '@/lib/utils';

export type IncidentStatus =
    | 'new'
    | 'triaging'
    | 'assigned'
    | 'in-progress'
    | 'resolved'
    | 'closed'
    | 'discarded';

const VARIANTS: Record<IncidentStatus, { label: string; className: string }> = {
    new: {
        label: 'Nuevo',
        className: 'bg-status-new/15 text-status-new border-status-new/40',
    },
    triaging: {
        label: 'Triage',
        className:
            'bg-status-triaging/15 text-status-triaging border-status-triaging/40',
    },
    assigned: {
        label: 'Asignado',
        className:
            'bg-status-assigned/15 text-status-assigned border-status-assigned/40',
    },
    'in-progress': {
        label: 'En curso',
        className:
            'bg-status-in-progress/15 text-status-in-progress border-status-in-progress/40',
    },
    resolved: {
        label: 'Resuelto',
        className:
            'bg-status-resolved/15 text-status-resolved border-status-resolved/40',
    },
    closed: {
        label: 'Cerrado',
        className:
            'bg-status-closed/15 text-status-closed border-status-closed/40',
    },
    discarded: {
        label: 'Descartado',
        className:
            'bg-status-discarded/15 text-status-discarded border-status-discarded/40',
    },
};

const DOT: Record<IncidentStatus, string> = {
    new: 'bg-status-new',
    triaging: 'bg-status-triaging',
    assigned: 'bg-status-assigned',
    'in-progress': 'bg-status-in-progress',
    resolved: 'bg-status-resolved',
    closed: 'bg-status-closed',
    discarded: 'bg-status-discarded',
};

interface Props {
    state: IncidentStatus;
    className?: string;
}

export function StatusPill({ state, className }: Props) {
    const v = VARIANTS[state];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-sm border px-1.5 py-1 text-[10px] font-semibold tracking-[0.02em] whitespace-nowrap',
                v.className,
                className,
            )}
        >
            <span
                className={cn('size-1.5 rounded-full', DOT[state])}
                aria-hidden="true"
            />
            {v.label}
        </span>
    );
}
