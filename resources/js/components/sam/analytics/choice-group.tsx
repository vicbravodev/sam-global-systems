import { cn } from '@/lib/utils';

export interface Choice<T extends string | number> {
    value: T;
    label: string;
    /** Tooltip nativo con la explicación de la opción. */
    hint?: string;
}

interface Props<T extends string | number> {
    options: Choice<T>[];
    value: T;
    onChange: (value: T) => void;
    'aria-label': string;
    size?: 'sm' | 'md';
    className?: string;
}

/**
 * Selector de una opción entre pocas (periodo, formato de archivo): todas a
 * la vista, la elegida resaltada. Sin opción "todos", a diferencia de
 * SegmentedFilter.
 */
export function ChoiceGroup<T extends string | number>({
    options,
    value,
    onChange,
    size = 'md',
    className,
    'aria-label': ariaLabel,
}: Props<T>) {
    return (
        <div
            role="radiogroup"
            aria-label={ariaLabel}
            className={cn(
                'inline-flex shrink-0 gap-0.5 rounded-md border border-border bg-surface-2 p-0.5',
                className,
            )}
        >
            {options.map((option) => {
                const active = option.value === value;

                return (
                    <button
                        key={String(option.value)}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        title={option.hint}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'cursor-pointer rounded-sm font-medium whitespace-nowrap transition-colors',
                            size === 'sm'
                                ? 'px-2 py-0.5 text-2xs'
                                : 'px-2.5 py-1 text-xs',
                            active
                                ? 'bg-surface-1 text-fg-1 shadow-xs'
                                : 'text-fg-3 hover:text-fg-1',
                        )}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
