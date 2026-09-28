import { LoaderCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

const VARIANTS: Record<
    string,
    { label: string; className: string; dot: string }
> = {
    completed: {
        label: 'Listo',
        className:
            'bg-severity-low/15 text-severity-low border-severity-low/40',
        dot: 'bg-severity-low',
    },
    running: {
        label: 'Generando',
        className:
            'bg-severity-info/15 text-severity-info border-severity-info/40',
        dot: 'bg-severity-info',
    },
    pending: {
        label: 'En cola',
        className:
            'bg-severity-info/15 text-severity-info border-severity-info/40',
        dot: 'bg-severity-info',
    },
    failed: {
        label: 'Falló',
        className:
            'bg-severity-critical/15 text-severity-critical border-severity-critical/40',
        dot: 'bg-severity-critical',
    },
    expired: {
        label: 'Caducado',
        className: 'bg-surface-3 text-fg-3 border-border',
        dot: 'bg-fg-3',
    },
};

/**
 * Estado de un reporte generado, con la geometría de StatusPill (que es
 * exclusiva de incidentes): color semántico + texto, nunca sólo color.
 */
export function ReportStatus({
    status,
    className,
}: {
    status: string | null;
    className?: string;
}) {
    const variant = VARIANTS[status ?? ''] ?? VARIANTS.pending;
    const working = status === 'running' || status === 'pending';

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-sm border px-1.5 py-1 text-3xs font-semibold tracking-label whitespace-nowrap',
                variant.className,
                className,
            )}
        >
            {working ? (
                <LoaderCircle
                    className="size-3 motion-safe:animate-spin"
                    aria-hidden="true"
                />
            ) : (
                <span
                    className={cn('size-1.5 rounded-full', variant.dot)}
                    aria-hidden="true"
                />
            )}
            {variant.label}
        </span>
    );
}
