import { cn } from '@/lib/utils';

export interface MeterProps {
    value: number;
    max?: number;
    /** Nombre accesible ("Unidades vigiladas contra el tope"). */
    label: string;
    /** Color de la barra según el umbral que decida quien la usa. */
    toneClassName?: string;
    /** Ancho mínimo visible de la barra, en %, para que 0 no desaparezca. */
    minPercent?: number;
    /** `progressbar` para avance en el tiempo; `meter` para cantidades. */
    role?: 'meter' | 'progressbar';
    /** Alto y ancho de la pista (por defecto `h-1.5`, ancho completo). */
    className?: string;
}

/** Barra horizontal de consumo o avance sobre una pista neutra. */
export function Meter({
    value,
    max = 100,
    label,
    toneClassName = 'bg-primary',
    minPercent = 0,
    role = 'meter',
    className,
}: MeterProps) {
    const ratio = max > 0 ? value / max : 0;
    const percent = Math.min(100, Math.max(minPercent, ratio * 100));

    return (
        <div
            className={cn(
                'h-1.5 overflow-hidden rounded-full bg-surface-3',
                className,
            )}
            role={role}
            aria-label={label}
            aria-valuemin={0}
            aria-valuemax={max}
            aria-valuenow={value}
        >
            <div
                className={cn('h-full rounded-full', toneClassName)}
                style={{ width: `${percent}%` }}
            />
        </div>
    );
}
