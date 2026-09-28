import { cn } from '@/lib/utils';
import type { EventPipelineStatus } from '@/types/events';

const VARIANTS: Record<
    EventPipelineStatus,
    { label: string; className: string; dot: string }
> = {
    normalized: {
        label: 'Normalizado',
        className: 'border-border bg-surface-3 text-fg-2',
        dot: 'bg-fg-3',
    },
    enrichment_pending: {
        label: 'Enriqueciendo',
        className:
            'border-severity-medium/40 bg-severity-medium/10 text-severity-medium',
        dot: 'bg-severity-medium motion-safe:animate-pulse',
    },
    enriched: {
        label: 'Enriquecido',
        className:
            'border-severity-low/40 bg-severity-low/10 text-severity-low',
        dot: 'bg-severity-low',
    },
    failed: {
        label: 'Fallido',
        className:
            'border-severity-critical/40 bg-severity-critical/10 text-severity-critical',
        dot: 'bg-severity-critical',
    },
    unmapped: {
        label: 'Sin mapear',
        className:
            'border-severity-high/40 bg-severity-high/10 text-severity-high',
        dot: 'bg-severity-high',
    },
};

/** Pipeline state pill for normalized events (Spanish, colored by outcome). */
export function PipelineStatusPill({
    status,
    label,
    className,
}: {
    status: EventPipelineStatus | string | null;
    label?: string | null;
    className?: string;
}) {
    const v =
        (status && VARIANTS[status as EventPipelineStatus]) ||
        VARIANTS.normalized;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-sm border px-1.5 py-1 text-3xs font-semibold tracking-label whitespace-nowrap',
                v.className,
                className,
            )}
        >
            <span
                className={cn('size-1.5 rounded-full', v.dot)}
                aria-hidden="true"
            />
            {label ?? v.label}
        </span>
    );
}
