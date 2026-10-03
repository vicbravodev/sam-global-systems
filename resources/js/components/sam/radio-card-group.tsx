import type { LucideIcon } from 'lucide-react';
import type { KeyboardEvent, ReactNode } from 'react';
import { cn } from '@/lib/utils';

const NEXT_KEYS = new Set(['ArrowDown', 'ArrowRight']);
const PREV_KEYS = new Set(['ArrowUp', 'ArrowLeft']);

export interface RadioCardGroupProps {
    /** Nombre accesible del grupo. */
    label: string;
    children: ReactNode;
    className?: string;
}

/**
 * Grupo de tarjetas seleccionables (`role="radiogroup"`). Las flechas mueven
 * el foco y la selección entre las opciones habilitadas, como un radio nativo.
 */
export function RadioCardGroup({
    label,
    children,
    className,
}: RadioCardGroupProps) {
    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const step = NEXT_KEYS.has(event.key)
            ? 1
            : PREV_KEYS.has(event.key)
              ? -1
              : 0;

        if (step === 0) {
            return;
        }

        const radios = Array.from(
            event.currentTarget.querySelectorAll<HTMLButtonElement>(
                'button[role="radio"]:not(:disabled)',
            ),
        );
        const current = radios.indexOf(
            document.activeElement as HTMLButtonElement,
        );

        if (current === -1 || radios.length === 0) {
            return;
        }

        event.preventDefault();
        const next = radios[(current + step + radios.length) % radios.length];
        next.focus();
        next.click();
    };

    return (
        <div
            role="radiogroup"
            aria-label={label}
            onKeyDown={onKeyDown}
            className={className}
        >
            {children}
        </div>
    );
}

export interface RadioCardProps {
    selected: boolean;
    onSelect: () => void;
    label: ReactNode;
    description?: ReactNode;
    /** Icono a la izquierda; toma el color primario al estar elegido. */
    icon?: LucideIcon;
    /** Elemento a la izquierda cuando no basta un icono (logo, indicador). */
    leading?: ReactNode;
    disabled?: boolean;
    className?: string;
}

/** Opción de un `RadioCardGroup`: tarjeta con título y ayuda opcional. */
export function RadioCard({
    selected,
    onSelect,
    label,
    description,
    icon: Icon,
    leading,
    disabled = false,
    className,
}: RadioCardProps) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={selected}
            disabled={disabled}
            onClick={onSelect}
            className={cn(
                'flex items-start gap-3 rounded-md border p-3 text-left transition-colors ease-(--ease-out) outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed motion-safe:duration-[--motion-fast]',
                selected
                    ? 'border-primary bg-primary/5'
                    : 'border-border hover:bg-surface-2',
                className,
            )}
        >
            {Icon && (
                <Icon
                    className={cn(
                        'mt-0.5 size-4 shrink-0',
                        selected ? 'text-primary' : 'text-fg-3',
                    )}
                    aria-hidden="true"
                />
            )}
            {leading}
            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="flex flex-wrap items-center gap-1.5 text-sm font-medium text-fg-1">
                    {label}
                </span>
                {description && (
                    <span className="text-xs leading-snug text-fg-3">
                        {description}
                    </span>
                )}
            </span>
        </button>
    );
}
