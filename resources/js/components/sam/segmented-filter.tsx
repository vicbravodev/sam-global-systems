import { cn } from '@/lib/utils';

export interface SegmentedOption {
    value: string;
    label: string;
    count?: number;
    /** Dot color class (e.g. 'bg-severity-critical') shown before the label. */
    dot?: string;
}

interface Props {
    options: SegmentedOption[];
    /** Selected value; `null` means the "all" segment. */
    value: string | null;
    onChange: (value: string | null) => void;
    allLabel?: string;
    allCount?: number;
    className?: string;
    'aria-label'?: string;
}

/**
 * One-click segmented filter with live counts: the reader sees the shape of
 * the list (how many per state) before choosing, instead of opening a
 * dropdown blind. `null` = no filter.
 */
export function SegmentedFilter({
    options,
    value,
    onChange,
    allLabel = 'Todos',
    allCount,
    className,
    'aria-label': ariaLabel,
}: Props) {
    const items: SegmentedOption[] = [
        { value: '', label: allLabel, count: allCount },
        ...options,
    ];

    return (
        <div
            role="group"
            aria-label={ariaLabel}
            className={cn(
                'scrollbar-none flex max-w-full items-center gap-1 overflow-x-auto',
                className,
            )}
        >
            {items.map((item) => {
                const active = (value ?? '') === item.value;

                return (
                    <button
                        key={item.value}
                        type="button"
                        aria-pressed={active}
                        onClick={() =>
                            onChange(item.value === '' ? null : item.value)
                        }
                        className={cn(
                            'flex shrink-0 items-center gap-1.5 rounded-full border px-2.5 py-1 text-2xs font-medium whitespace-nowrap transition-colors',
                            active
                                ? 'border-primary/40 bg-primary/10 text-primary'
                                : 'border-border bg-surface-1 text-fg-2 hover:border-border-strong hover:text-fg-1',
                        )}
                    >
                        {item.dot && (
                            <span
                                className={cn(
                                    'size-1.5 rounded-full',
                                    item.dot,
                                )}
                                aria-hidden="true"
                            />
                        )}
                        {item.label}
                        {item.count !== undefined && (
                            <span
                                className={cn(
                                    'rounded-full px-1.5 font-mono text-3xs tabular-nums',
                                    active
                                        ? 'bg-primary/15 text-primary'
                                        : 'bg-surface-3 text-fg-3',
                                )}
                            >
                                {item.count}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}
