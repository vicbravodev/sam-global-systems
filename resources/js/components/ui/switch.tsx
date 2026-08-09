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
 * Accessible toggle switch, token-styled to match the design system .sam-switch
 * (health-ok tint when on). Dependency-free — no Radix package required.
 */
export function Switch({ checked, onCheckedChange, disabled, id, className, ...aria }: SwitchProps) {
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
                'relative h-[22px] w-[38px] shrink-0 rounded-full border transition-colors motion-safe:duration-[--motion-fast] ease-(--ease-out) focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                checked ? 'border-health-ok/50 bg-health-ok/35' : 'border-border bg-surface-3',
                className,
            )}
        >
            <span
                className={cn(
                    'absolute top-[2px] left-[2px] size-4 rounded-full transition-transform motion-safe:duration-[--motion-fast] ease-(--ease-out)',
                    checked ? 'translate-x-4 bg-health-ok' : 'bg-fg-3',
                )}
            />
        </button>
    );
}
