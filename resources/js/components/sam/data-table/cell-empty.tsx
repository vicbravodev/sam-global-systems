import { cn } from '@/lib/utils';

/**
 * Placeholder único para celdas sin valor (F2.4). Convención:
 * - `dash` (default): texto/número ausente → em-dash neutro.
 * - `person`: persona sin asignar → "Sin asignar" en cursiva.
 * El copy de mensajes NO usa em-dash; eso es solo para celdas.
 */
export function CellEmpty({
    variant = 'dash',
    className,
}: {
    variant?: 'dash' | 'person';
    className?: string;
}) {
    if (variant === 'person') {
        return (
            <span className={cn('text-[12px] text-fg-3 italic', className)}>
                Sin asignar
            </span>
        );
    }

    return <span className={cn('text-fg-3', className)}>—</span>;
}
