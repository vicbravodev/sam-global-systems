import { StatusBadge } from '@/components/sam/status-badge';
import type { ToneLabel } from '@/lib/tone';
import type { EventPipelineStatus } from '@/types/events';

const PIPELINE_STATUS: Record<EventPipelineStatus, ToneLabel> = {
    normalized: { label: 'Normalizado', tone: 'neutral' },
    enrichment_pending: { label: 'Enriqueciendo', tone: 'warn' },
    enriched: { label: 'Enriquecido', tone: 'ok' },
    failed: { label: 'Fallido', tone: 'critical' },
    unmapped: { label: 'Sin mapear', tone: 'high' },
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
        (status && PIPELINE_STATUS[status as EventPipelineStatus]) ||
        PIPELINE_STATUS.normalized;

    return (
        <StatusBadge
            tone={v.tone}
            label={label ?? v.label}
            dot
            pulse={status === 'enrichment_pending'}
            className={className}
        />
    );
}
