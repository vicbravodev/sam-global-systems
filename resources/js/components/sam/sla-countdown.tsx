import { cn } from '@/lib/utils';

interface Props {
    /** Remaining seconds. <=0 means expired. */
    seconds: number;
    /** Total budget in seconds (used to compute % consumed). */
    total: number;
    className?: string;
}

export function SlaCountdown({ seconds, total, className }: Props) {
    const safeSeconds = Math.max(0, seconds);
    const consumed = total > 0 ? 1 - seconds / total : 1;
    const expired = seconds <= 0;
    const critical = expired || consumed >= 0.95;
    const high = !critical && consumed >= 0.8;

    const colorClass = critical
        ? 'text-severity-critical'
        : high
          ? 'text-severity-high'
          : 'text-fg-2';

    const m = Math.floor(safeSeconds / 60);
    const s = safeSeconds % 60;
    const label = expired
        ? 'VENCIDO'
        : `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;

    return (
        <span
            className={cn(
                'font-mono text-xs font-medium tabular-nums',
                colorClass,
                className,
            )}
        >
            {high && !critical && (
                <span aria-hidden="true" className="mr-1">
                    ▲
                </span>
            )}
            {label}
        </span>
    );
}
