import { cn } from '@/lib/utils';
import type { DriverStatusValue } from '@/types/drivers';

const VARIANTS: Record<
    DriverStatusValue,
    { label: string; className: string }
> = {
    active: {
        label: 'Activo',
        className:
            'bg-severity-low/15 text-severity-low border-severity-low/40',
    },
    off_duty: {
        label: 'Fuera de turno',
        className: 'bg-surface-3 text-fg-3 border-border',
    },
    unavailable: {
        label: 'No disponible',
        className: 'bg-surface-3 text-fg-3 border-border',
    },
    suspended: {
        label: 'Suspendido',
        className:
            'bg-severity-critical/15 text-severity-critical border-severity-critical/40',
    },
    under_review: {
        label: 'En revisión',
        className:
            'bg-severity-medium/15 text-severity-medium border-severity-medium/40',
    },
};

const DOT: Record<DriverStatusValue, string> = {
    active: 'bg-severity-low',
    off_duty: 'bg-fg-3',
    unavailable: 'bg-fg-3',
    suspended: 'bg-severity-critical',
    under_review: 'bg-severity-medium',
};

interface Props {
    status: DriverStatusValue;
    className?: string;
}

export function DriverStatusBadge({ status, className }: Props) {
    const v = VARIANTS[status];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-sm border px-1.5 py-1 text-3xs font-semibold tracking-label whitespace-nowrap',
                v.className,
                className,
            )}
        >
            <span
                className={cn('size-1.5 rounded-full', DOT[status])}
                aria-hidden="true"
            />
            {v.label}
        </span>
    );
}
