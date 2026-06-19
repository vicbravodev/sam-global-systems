import { cn } from '@/lib/utils';

export interface TabItem {
    key: string;
    label: string;
    /** Optional count badge; omit for tabs without a count. */
    count?: number;
}

export interface TabBarProps {
    items: TabItem[];
    value: string;
    onChange: (key: string) => void;
    className?: string;
    'aria-label'?: string;
}

/**
 * Underline tab strip with optional count badges. Matches the design system's
 * .sam-tab pattern (active = primary underline). Keyboard: ArrowLeft/Right move
 * focus and selection between tabs.
 */
export function TabBar({
    items,
    value,
    onChange,
    className,
    'aria-label': ariaLabel,
}: TabBarProps) {
    const move = (dir: 1 | -1) => {
        const idx = items.findIndex((t) => t.key === value);
        const next = items[(idx + dir + items.length) % items.length];

        if (next) {
            onChange(next.key);
        }
    };

    return (
        <div
            role="tablist"
            aria-label={ariaLabel}
            className={cn(
                'flex items-center gap-0.5 border-b border-border',
                className,
            )}
        >
            {items.map((t) => {
                const active = t.key === value;

                return (
                    <button
                        key={t.key}
                        role="tab"
                        type="button"
                        aria-selected={active}
                        tabIndex={active ? 0 : -1}
                        onClick={() => onChange(t.key)}
                        onKeyDown={(e) => {
                            if (e.key === 'ArrowRight') {
                                e.preventDefault();
                                move(1);
                            } else if (e.key === 'ArrowLeft') {
                                e.preventDefault();
                                move(-1);
                            }
                        }}
                        className={cn(
                            '-mb-px flex items-center gap-1.5 border-b-2 px-2.5 py-2.5 text-sm font-medium whitespace-nowrap transition-colors ease-(--ease-out) motion-safe:duration-[--motion-fast]',
                            active
                                ? 'border-primary text-fg-1'
                                : 'border-transparent text-fg-3 hover:text-fg-1',
                        )}
                    >
                        {t.label}
                        {t.count != null && (
                            <span
                                className={cn(
                                    'rounded-full px-1.5 font-mono text-2xs tabular-nums',
                                    active
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-surface-3 text-fg-2',
                                )}
                            >
                                {t.count}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}
