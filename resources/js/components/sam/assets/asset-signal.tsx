import { RelativeTime } from '@/components/sam/relative-time';
import { cn } from '@/lib/utils';

const DAY_MINUTES = 1440;

function minutesSince(iso: string): number {
    return Math.max(0, Math.floor((Date.now() - Date.parse(iso)) / 60000));
}

function absoluteDate(iso: string): string {
    return new Date(iso).toLocaleDateString('es', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

interface Props {
    /** Latest REAL signal (location/telemetry), never the bulk sync bump. */
    lastSignalAt: string | null;
    /** Whether the asset has a device currently attached. */
    hasDevice: boolean;
    /** Prefix the fresh-signal case with "Señal" (detail header). */
    withPrefix?: boolean;
    className?: string;
}

/**
 * Honest "last seen" indicator (C1-a): derived from the asset's own signal.
 * Assets silent for more than 24 h show an absolute date instead of a
 * misleading "hace N min", and assets without a device are flagged as such.
 */
export function AssetSignal({
    lastSignalAt,
    hasDevice,
    withPrefix = false,
    className,
}: Props) {
    const deviceNote = hasDevice ? '' : ' · sin dispositivo vinculado';

    if (lastSignalAt === null) {
        return (
            <span className={cn('text-2xs text-fg-3', className)}>
                Sin señal{deviceNote}
            </span>
        );
    }

    const minutes = minutesSince(lastSignalAt);

    if (minutes >= DAY_MINUTES) {
        return (
            <span className={cn('text-2xs text-fg-3', className)}>
                Sin señal desde {absoluteDate(lastSignalAt)}
                {deviceNote}
            </span>
        );
    }

    return (
        <span className={cn('text-2xs text-fg-3', className)}>
            {withPrefix ? 'Señal ' : ''}
            <RelativeTime minutes={minutes} />
            {deviceNote}
        </span>
    );
}
