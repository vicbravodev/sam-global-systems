import { Gauge, Navigation, Zap } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo } from 'react';
import {
    MOVING_SPEED_KPH,
    TELEMETRY_ICONS,
    telemetryValue,
} from '@/components/sam/assets/detail/telemetry';
import { RelativeTime } from '@/components/sam/relative-time';
import { formatDateTime, formatNumber } from '@/lib/format';
import { isFresh, minutesSince } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { AssetShowProps, TelemetryEntry } from '@/types/assets';

function NowTile({
    icon: Icon,
    label,
    value,
    unit,
    recordedAt,
    tone = 'neutral',
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    unit?: string | null;
    recordedAt: string | null;
    tone?: 'neutral' | 'ok' | 'warn' | 'critical';
}) {
    const stale = recordedAt !== null && !isFresh(recordedAt);

    return (
        <div className="flex min-w-0 flex-col gap-0.5 bg-surface-1 px-4 py-3">
            <span className="sam-caps flex items-center gap-1.5">
                <Icon
                    className="size-3"
                    strokeWidth={1.75}
                    aria-hidden="true"
                />
                <span className="truncate">{label}</span>
            </span>
            <span
                className={cn(
                    'flex items-baseline gap-1 font-sans text-xl leading-tight font-semibold tracking-tight',
                    tone === 'ok' && 'text-severity-low',
                    tone === 'warn' && 'text-severity-medium',
                    tone === 'critical' && 'text-severity-critical',
                    tone === 'neutral' && 'text-fg-1',
                    stale && 'text-fg-3',
                )}
            >
                {value}
                {unit && (
                    <span className="text-xs font-normal text-fg-3">
                        {unit}
                    </span>
                )}
            </span>
            {recordedAt && (
                <span
                    className="text-2xs text-fg-3"
                    title={formatDateTime(recordedAt)}
                >
                    <RelativeTime minutes={minutesSince(recordedAt)} />
                </span>
            )}
        </div>
    );
}

export function NowStrip({
    asset,
    telemetry,
}: {
    asset: AssetShowProps['asset'];
    telemetry: TelemetryEntry[];
}) {
    const byType = useMemo(
        () => new Map(telemetry.map((entry) => [entry.type, entry])),
        [telemetry],
    );

    const tiles: React.ReactNode[] = [];

    // Same reading as the telemetry card and the fleet row: the newest of
    // position and speed telemetry, with the server's staleness verdict.
    const speed = asset.currentSpeed;

    if (speed !== null) {
        const moving = !speed.stale && speed.kph > MOVING_SPEED_KPH;

        tiles.push(
            <NowTile
                key="speed"
                icon={moving ? Navigation : Gauge}
                label={
                    moving
                        ? 'En ruta'
                        : speed.stale
                          ? 'Última velocidad'
                          : 'Detenida'
                }
                value={formatNumber(speed.kph, {
                    maximumFractionDigits: 0,
                })}
                unit="km/h"
                recordedAt={speed.recordedAt}
                tone={moving ? 'ok' : 'neutral'}
            />,
        );
    }

    (
        [
            'ignition',
            'fuel',
            'odometer',
            'temperature',
            'battery',
            'camera_status',
        ] as const
    ).forEach((type) => {
        const entry = byType.get(type);

        if (!entry) {
            return;
        }

        const { value, unit } = telemetryValue(entry.data);
        const numeric =
            typeof entry.data?.value === 'number' ? entry.data.value : null;
        const tone =
            type === 'fuel' && numeric !== null && numeric < 15
                ? 'critical'
                : type === 'fuel' && numeric !== null && numeric < 30
                  ? 'warn'
                  : type === 'ignition' && value === 'Encendido'
                    ? 'ok'
                    : 'neutral';

        tiles.push(
            <NowTile
                key={type}
                icon={TELEMETRY_ICONS[type] ?? Zap}
                label={entry.label}
                value={value}
                unit={unit}
                recordedAt={entry.recordedAt}
                tone={tone}
            />,
        );
    });

    if (tiles.length === 0) {
        return null;
    }

    return (
        <div className="scrollbar-none overflow-x-auto rounded-lg border border-border bg-border">
            <div className="grid auto-cols-[minmax(140px,1fr)] grid-flow-col gap-px">
                {tiles}
            </div>
        </div>
    );
}
