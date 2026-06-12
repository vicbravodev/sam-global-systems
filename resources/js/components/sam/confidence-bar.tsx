import { cn } from '@/lib/utils';

interface Props {
    /** Value between 0 and 1. */
    value: number;
    className?: string;
}

export function ConfidenceBar({ value, className }: Props) {
    const clamped = Math.max(0, Math.min(1, value));
    const pct = Math.round(clamped * 100);
    const color =
        clamped > 0.75
            ? 'text-confidence-high'
            : clamped > 0.5
              ? 'text-confidence-mid'
              : 'text-confidence-low';

    return (
        <div className={cn('flex items-center gap-2', className)}>
            <div className="relative h-1.5 w-30 overflow-hidden rounded-full bg-surface-3">
                <div
                    className="absolute inset-y-0 left-0 rounded-full"
                    style={{
                        width: `${pct}%`,
                        background:
                            'linear-gradient(90deg, var(--confidence-low), var(--confidence-mid) 50%, var(--confidence-high))',
                    }}
                    role="progressbar"
                    aria-valuenow={pct}
                    aria-valuemin={0}
                    aria-valuemax={100}
                />
            </div>
            <span className={cn('font-mono text-2xs tabular-nums', color)}>
                {pct} %
            </span>
        </div>
    );
}
