import { useEffect, useState } from 'react';
import { formatDateTime } from '@/lib/format';
import { durationLabel } from '@/lib/time';
import { cn } from '@/lib/utils';

// Past these ages the fleet data stops reading as "live": the telematics feed
// normally lands a batch every ~5 s.
const FRESH_SECONDS = 30;
const STALE_SECONDS = 120;

interface Props {
    /** ISO time of the newest position received, or null before any. */
    newestAt: string | null;
    className?: string;
}

/**
 * How old the newest position on screen is, ticking every second: the lag an
 * operator should know before trusting where a unit is drawn.
 */
export function DataFreshness({ newestAt, className }: Props) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const id = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(id);
    }, []);

    if (newestAt === null) {
        return null;
    }

    const seconds = Math.max(
        0,
        Math.round((now - new Date(newestAt).getTime()) / 1000),
    );
    const dotClass =
        seconds <= FRESH_SECONDS
            ? 'bg-health-ok'
            : seconds <= STALE_SECONDS
              ? 'bg-health-warn'
              : 'bg-health-down';

    return (
        <span
            className={cn(
                'inline-flex items-center gap-2 rounded-full border border-border bg-surface-2 py-1 pr-2.5 pl-2 text-xs font-medium',
                className,
            )}
            title={`Posición más reciente: ${formatDateTime(newestAt)}`}
        >
            <span
                className={cn('size-2 rounded-full', dotClass)}
                aria-hidden="true"
            />
            <span className="text-fg-3">
                Datos de hace{' '}
                <span className="text-fg-1 tabular-nums">
                    {durationLabel(seconds)}
                </span>
            </span>
        </span>
    );
}
