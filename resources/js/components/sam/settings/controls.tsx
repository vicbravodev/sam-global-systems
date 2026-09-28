import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** Botón-píldora con estado (canales, días de la semana). */
export function ChipToggle({
    active,
    onToggle,
    disabled,
    children,
    label,
    className,
}: {
    active: boolean;
    onToggle: () => void;
    disabled?: boolean;
    children: ReactNode;
    /** Nombre accesible si el contenido es abreviado. */
    label?: string;
    className?: string;
}) {
    return (
        <button
            type="button"
            disabled={disabled}
            aria-pressed={active}
            aria-label={label}
            title={label}
            onClick={onToggle}
            className={cn(
                'inline-flex items-center justify-center rounded-md border px-2.5 py-1 text-xs font-medium transition-colors ease-(--ease-out) disabled:cursor-not-allowed disabled:opacity-60 motion-safe:duration-[--motion-fast]',
                active
                    ? 'border-primary bg-primary/10 text-fg-1'
                    : 'border-border text-fg-3 hover:text-fg-1',
                className,
            )}
        >
            {children}
        </button>
    );
}

/** Píldora de estado activo/inactivo con texto (nunca sólo color). */
export function StatePill({
    on,
    onLabel = 'Activa',
    offLabel = 'Inactiva',
}: {
    on: boolean;
    onLabel?: string;
    offLabel?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-sm border px-1.5 py-0.5 text-2xs font-medium whitespace-nowrap',
                on
                    ? 'border-severity-low/40 bg-severity-low/10 text-severity-low'
                    : 'border-border bg-surface-3 text-fg-3',
            )}
        >
            <span
                className={cn(
                    'size-1.5 rounded-full',
                    on ? 'bg-severity-low' : 'bg-fg-3',
                )}
                aria-hidden
            />
            {on ? onLabel : offLabel}
        </span>
    );
}
