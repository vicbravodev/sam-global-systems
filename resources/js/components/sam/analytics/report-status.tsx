import { StatusBadge } from '@/components/sam/status-badge';
import type { ToneLabel } from '@/lib/tone';

const REPORT_STATUS: Record<string, ToneLabel> & { pending: ToneLabel } = {
    completed: { label: 'Listo', tone: 'ok' },
    running: { label: 'Generando', tone: 'info' },
    pending: { label: 'En cola', tone: 'info' },
    failed: { label: 'Falló', tone: 'critical' },
    expired: { label: 'Caducado', tone: 'neutral' },
};

/** Estado de un reporte generado: color semántico + texto, nunca sólo color. */
export function ReportStatus({
    status,
    className,
}: {
    status: string | null;
    className?: string;
}) {
    const variant = REPORT_STATUS[status ?? ''] ?? REPORT_STATUS.pending;
    const working = status === 'running' || status === 'pending';

    return (
        <StatusBadge
            {...variant}
            dot
            icon={working ? 'spinner' : undefined}
            className={className}
        />
    );
}
