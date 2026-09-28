import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export type PulseTone =
    | 'neutral'
    | 'ok'
    | 'warn'
    | 'critical'
    | 'info'
    | 'primary';

const VALUE_TONE: Record<PulseTone, string> = {
    neutral: 'text-fg-1',
    ok: 'text-severity-low',
    warn: 'text-severity-medium',
    critical: 'text-severity-critical',
    info: 'text-severity-info',
    primary: 'text-primary',
};

const DOT_TONE: Record<PulseTone, string> = {
    neutral: 'bg-fg-3',
    ok: 'bg-severity-low',
    warn: 'bg-severity-medium',
    critical: 'bg-severity-critical',
    info: 'bg-severity-info',
    primary: 'bg-primary',
};

export interface PulseStatProps {
    label: string;
    value: number | string;
    /** Secondary line under the value (e.g. "de 42"). */
    hint?: ReactNode;
    tone?: PulseTone;
    icon?: LucideIcon;
    /** Makes the tile a toggle that applies/clears a list filter. */
    onClick?: () => void;
    active?: boolean;
    /** Small live indicator (pulsing dot) for "right now" figures. */
    live?: boolean;
    className?: string;
}

/**
 * Compact stat tile for list-page headers (the dense sibling of `Kpi`):
 * caps label, proportional-figure value, optional hint and tone. When
 * `onClick` is given the tile doubles as a quick filter and shows its
 * pressed state, so the numbers are not decoration but a way in.
 */
export function PulseStat({
    label,
    value,
    hint,
    tone = 'neutral',
    icon: Icon,
    onClick,
    active = false,
    live = false,
    className,
}: PulseStatProps) {
    const Tag = onClick ? 'button' : 'div';

    return (
        <Tag
            type={onClick ? 'button' : undefined}
            onClick={onClick}
            aria-pressed={onClick ? active : undefined}
            className={cn(
                'group relative flex min-w-0 flex-col gap-0.5 bg-surface-1 px-4 py-2.5 text-left transition-colors',
                onClick &&
                    'cursor-pointer outline-none hover:bg-surface-2 focus-visible:bg-surface-2',
                active && 'bg-primary/[8%] hover:bg-primary/[12%]',
                className,
            )}
        >
            <span className="flex items-center gap-1.5 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                {Icon && (
                    <Icon
                        className="size-3 shrink-0"
                        strokeWidth={1.75}
                        aria-hidden="true"
                    />
                )}
                <span className="truncate">{label}</span>
                {live && (
                    <span className="relative ml-auto flex size-1.5">
                        <span
                            className={cn(
                                'absolute inline-flex size-full rounded-full opacity-60 motion-safe:animate-ping',
                                DOT_TONE[tone],
                            )}
                        />
                        <span
                            className={cn(
                                'relative inline-flex size-1.5 rounded-full',
                                DOT_TONE[tone],
                            )}
                        />
                    </span>
                )}
            </span>
            <span
                className={cn(
                    'font-sans text-xl leading-tight font-semibold tracking-tight',
                    VALUE_TONE[tone],
                )}
            >
                {value}
            </span>
            {hint && (
                <span className="truncate text-2xs text-fg-3">{hint}</span>
            )}
            {active && (
                <span
                    className="absolute inset-x-0 bottom-0 h-0.5 bg-primary"
                    aria-hidden="true"
                />
            )}
        </Tag>
    );
}

export interface PulseStripProps {
    children: ReactNode;
    className?: string;
}

/**
 * Hairline-separated strip of `PulseStat` tiles for the top of a list page.
 * Horizontal scroll under md so seven tiles never squeeze the phone layout.
 */
export function PulseStrip({ children, className }: PulseStripProps) {
    return (
        <div
            className={cn(
                'scrollbar-none shrink-0 overflow-x-auto border-b border-border bg-border',
                className,
            )}
        >
            <div className="grid auto-cols-[minmax(132px,1fr)] grid-flow-col gap-px">
                {children}
            </div>
        </div>
    );
}
