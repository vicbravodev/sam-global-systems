import { cn } from '@/lib/utils';
import type { AssetStatusValue } from '@/types/assets';

const VARIANTS: Record<AssetStatusValue, { label: string; className: string }> =
    {
        active: {
            label: 'Activo',
            className:
                'bg-severity-low/15 text-severity-low border-severity-low/40',
        },
        inactive: {
            label: 'Inactivo',
            className: 'bg-surface-3 text-fg-3 border-border',
        },
        offline: {
            label: 'Sin conexión',
            className: 'bg-surface-3 text-fg-3 border-border',
        },
        alert: {
            label: 'Alerta',
            className:
                'bg-severity-high/15 text-severity-high border-severity-high/40',
        },
        critical: {
            label: 'Crítico',
            className:
                'bg-severity-critical/15 text-severity-critical border-severity-critical/40',
        },
        maintenance: {
            label: 'Mantenimiento',
            className:
                'bg-severity-medium/15 text-severity-medium border-severity-medium/40',
        },
    };

const DOT: Record<AssetStatusValue, string> = {
    active: 'bg-severity-low',
    inactive: 'bg-fg-3',
    offline: 'bg-fg-3',
    alert: 'bg-severity-high',
    critical: 'bg-severity-critical',
    maintenance: 'bg-severity-medium',
};

interface Props {
    status: AssetStatusValue;
    className?: string;
}

export function AssetStatusBadge({ status, className }: Props) {
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
