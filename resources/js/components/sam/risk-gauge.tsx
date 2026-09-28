import { Minus, TrendingDown, TrendingUp } from 'lucide-react';

import { cn } from '@/lib/utils';

export type RiskLevel = 'low' | 'medium' | 'high' | 'critical';
export type RiskTrend = 'baseline' | 'improving' | 'deteriorating' | 'stable';

export const RISK_LEVEL_LABELS: Record<RiskLevel, string> = {
    low: 'Bajo',
    medium: 'Medio',
    high: 'Alto',
    critical: 'Crítico',
};

const LEVEL_TEXT: Record<RiskLevel, string> = {
    low: 'text-severity-low',
    medium: 'text-severity-medium',
    high: 'text-severity-high',
    critical: 'text-severity-critical',
};

const LEVEL_BG: Record<RiskLevel, string> = {
    low: 'bg-severity-low',
    medium: 'bg-severity-medium',
    high: 'bg-severity-high',
    critical: 'bg-severity-critical',
};

const LEVEL_STROKE: Record<RiskLevel, string> = {
    low: 'var(--severity-low)',
    medium: 'var(--severity-medium)',
    high: 'var(--severity-high)',
    critical: 'var(--severity-critical)',
};

/** Level derived from the score when the backend did not persist one. */
export function levelForScore(score: number): RiskLevel {
    if (score >= 75) {
        return 'critical';
    }

    if (score >= 50) {
        return 'high';
    }

    if (score >= 25) {
        return 'medium';
    }

    return 'low';
}

export function resolveRiskLevel(
    level: string | null | undefined,
    score: number | null,
): RiskLevel | null {
    if (
        level === 'low' ||
        level === 'medium' ||
        level === 'high' ||
        level === 'critical'
    ) {
        return level;
    }

    return score !== null ? levelForScore(score) : null;
}

interface BarProps {
    score: number | null;
    level?: string | null;
    trend?: string | null;
    className?: string;
}

/**
 * Inline risk meter for table rows: a 0–100 track filled in the level hue,
 * the score in mono beside it and a trend arrow when the last recalculation
 * moved the score. Reads at a glance across 50 rows.
 */
export function RiskBar({ score, level, trend, className }: BarProps) {
    if (score === null) {
        return <span className="text-fg-3">—</span>;
    }

    const resolved = resolveRiskLevel(level, score) ?? 'low';
    const pct = Math.max(0, Math.min(100, score));

    return (
        <span
            className={cn('inline-flex items-center gap-2', className)}
            title={`Riesgo ${RISK_LEVEL_LABELS[resolved].toLowerCase()} · ${score.toFixed(0)} / 100`}
        >
            <span className="relative h-1.5 w-16 overflow-hidden rounded-full bg-surface-3">
                <span
                    className={cn(
                        'absolute inset-y-0 left-0 rounded-full',
                        LEVEL_BG[resolved],
                    )}
                    style={{ width: `${pct}%` }}
                />
            </span>
            <span
                className={cn(
                    'font-mono text-xs font-semibold tabular-nums',
                    LEVEL_TEXT[resolved],
                )}
            >
                {score.toFixed(0)}
            </span>
            <TrendGlyph trend={trend} />
        </span>
    );
}

function TrendGlyph({ trend }: { trend?: string | null }) {
    if (trend === 'deteriorating') {
        return (
            <TrendingUp
                className="size-3 text-severity-critical"
                aria-label="Empeorando"
            />
        );
    }

    if (trend === 'improving') {
        return (
            <TrendingDown
                className="size-3 text-severity-low"
                aria-label="Mejorando"
            />
        );
    }

    if (trend === 'stable') {
        return <Minus className="size-3 text-fg-3" aria-label="Estable" />;
    }

    return null;
}

export const TREND_LABELS: Record<RiskTrend, string> = {
    baseline: 'Primer cálculo',
    improving: 'Mejorando',
    deteriorating: 'Empeorando',
    stable: 'Estable',
};

interface GaugeProps {
    score: number;
    level?: string | null;
    size?: number;
    className?: string;
}

/**
 * Half-ring gauge for the driver's risk score. One hue (the level's), a
 * recessive track, the number in the middle. No ticks, no rainbow: the
 * level label under the number carries the meaning for CVD readers.
 */
export function RiskGauge({ score, level, size = 148, className }: GaugeProps) {
    const resolved = resolveRiskLevel(level, score) ?? 'low';
    const pct = Math.max(0, Math.min(100, score)) / 100;
    const stroke = 10;
    const r = (size - stroke) / 2;
    const cx = size / 2;
    const cy = size / 2;
    // Half circle from 180° to 360° (left to right over the top).
    const arc = Math.PI * r;
    const dash = arc * pct;

    return (
        <div
            className={cn('relative', className)}
            style={{ width: size, height: size / 2 + stroke }}
            role="img"
            aria-label={`Riesgo ${score.toFixed(0)} de 100, nivel ${RISK_LEVEL_LABELS[resolved].toLowerCase()}`}
        >
            <svg
                width={size}
                height={size / 2 + stroke}
                viewBox={`0 0 ${size} ${size / 2 + stroke}`}
                className="block"
            >
                <path
                    d={`M ${cx - r} ${cy} A ${r} ${r} 0 0 1 ${cx + r} ${cy}`}
                    fill="none"
                    stroke="var(--surface-3)"
                    strokeWidth={stroke}
                    strokeLinecap="round"
                />
                <path
                    d={`M ${cx - r} ${cy} A ${r} ${r} 0 0 1 ${cx + r} ${cy}`}
                    fill="none"
                    stroke={LEVEL_STROKE[resolved]}
                    strokeWidth={stroke}
                    strokeLinecap="round"
                    strokeDasharray={`${dash} ${arc}`}
                    className="transition-[stroke-dasharray] duration-700 ease-(--ease-out)"
                />
            </svg>
            <div className="absolute inset-x-0 bottom-0 flex flex-col items-center leading-none">
                <span
                    className={cn(
                        'font-sans text-3xl font-semibold tracking-tight',
                        LEVEL_TEXT[resolved],
                    )}
                >
                    {score.toFixed(0)}
                </span>
                <span className="mt-1 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                    {RISK_LEVEL_LABELS[resolved]}
                </span>
            </div>
        </div>
    );
}
