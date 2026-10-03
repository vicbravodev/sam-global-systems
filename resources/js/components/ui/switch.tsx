import { TONE_DOT, TONE_SURFACE } from '@/lib/tone';
import { cn } from '@/lib/utils';

export interface SwitchProps {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    disabled?: boolean;
    id?: string;
    'aria-label'?: string;
    'aria-labelledby'?: string;
    className?: string;
}

/**
 * Interruptor accesible con el estilo de `.sam-switch` del design system. Encendido
 * usa el tono `ok` de `lib/tone` (es un estado, no salud de infraestructura).
 * Sin dependencias: no requiere Radix.
 */
export function Switch({
    checked,
    onCheckedChange,
    disabled,
    id,
    className,
    ...aria
}: SwitchProps) {
    return (
        <button
            id={id}
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={aria['aria-label']}
            aria-labelledby={aria['aria-labelledby']}
            disabled={disabled}
            onClick={() => !disabled && onCheckedChange(!checked)}
            className={cn(
                'relative h-[22px] w-[38px] shrink-0 rounded-full border transition-colors ease-(--ease-out) focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 motion-safe:duration-[--motion-fast]',
                checked ? TONE_SURFACE.ok : 'border-border bg-surface-3',
                className,
            )}
        >
            <span
                className={cn(
                    'absolute top-[2px] left-[2px] size-4 rounded-full transition-transform ease-(--ease-out) motion-safe:duration-[--motion-fast]',
                    checked ? ['translate-x-4', TONE_DOT.ok] : TONE_DOT.neutral,
                )}
            />
        </button>
    );
}
