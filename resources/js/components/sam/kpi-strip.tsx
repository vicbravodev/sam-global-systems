import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export interface DeltaProps {
    /** Signed change, e.g. 12 or -4. */
    value: number;
    /** Suffix rendered after the number, e.g. '%'. */
    unit?: string;
    /** When true, a negative value is "good" (green) — for metrics like MTTA. */
    invert?: boolean;
    className?: string;
}

/** Signed, color-coded change indicator with a trend arrow. */
export function Delta({
    value,
    unit = '%',
    invert = false,
    className,
}: DeltaProps) {
    if (value === 0) {
        return (
            <span
                className={cn(
                    'inline-flex items-center gap-0.5 font-mono text-2xs text-fg-3 tabular-nums',
                    className,
                )}
            >
                0{unit}
            </span>
        );
    }

    const up = value > 0;
    const good = invert ? !up : up;
    const Arrow = up ? ArrowUpRight : ArrowDownRight;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-0.5 font-mono text-2xs tabular-nums',
                good ? 'text-health-ok' : 'text-severity-high',
                className,
            )}
        >
            <Arrow className="size-3" aria-hidden="true" />
            {up ? '+' : ''}
            {value}
            {unit}
        </span>
    );
}

export interface KpiProps {
    label: string;
    value: ReactNode;
    unit?: string;
    delta?: DeltaProps;
    /** Optional visual (e.g. <SparkArea/> or a <Bar/>) rendered under the value. */
    sparkline?: ReactNode;
    className?: string;
}

/** A single KPI card. Big tabular-mono value, caps label, optional delta + sparkline. */
export function Kpi({
    label,
    value,
    unit,
    delta,
    sparkline,
    className,
}: KpiProps) {
    return (
        <div
            className={cn(
                'relative flex min-h-[100px] flex-col gap-1 overflow-hidden bg-surface-1 p-4',
                className,
            )}
        >
            <span className="text-2xs font-semibold tracking-caps text-fg-3 uppercase">
                {label}
            </span>
            <div className="flex items-baseline gap-1">
                <span className="font-mono text-2xl font-semibold tracking-tight text-fg-1 tabular-nums">
                    {value}
                </span>
                {unit && <span className="text-xs text-fg-3">{unit}</span>}
            </div>
            {delta && <Delta {...delta} />}
            {sparkline && <div className="mt-auto pt-2">{sparkline}</div>}
        </div>
    );
}

export interface KpiStripProps {
    children: ReactNode;
    /** Column count at the lg breakpoint. Default 4. */
    cols?: 3 | 4 | 5 | 6;
    className?: string;
}

const COLS: Record<NonNullable<KpiStripProps['cols']>, string> = {
    3: 'lg:grid-cols-3',
    4: 'lg:grid-cols-4',
    5: 'lg:grid-cols-5',
    6: 'lg:grid-cols-6',
};

/**
 * Hairline-separated KPI strip. Uses a 1px gap over a border-tinted surface so
 * the cards read as one franja (matching the dashboard cockpit), collapsing to
 * 2 columns on mobile.
 */
export function KpiStrip({ children, cols = 4, className }: KpiStripProps) {
    return (
        <div
            className={cn(
                'grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-border bg-border',
                COLS[cols],
                className,
            )}
        >
            {children}
        </div>
    );
}
