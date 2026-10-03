import { Zap } from 'lucide-react';
import {
    TELEMETRY_ICONS,
    telemetryValue,
} from '@/components/sam/assets/detail/telemetry';
import { RelativeTime } from '@/components/sam/relative-time';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import { minutesSince } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { TelemetryEntry } from '@/types/assets';

export function TelemetryCard({ telemetry }: { telemetry: TelemetryEntry[] }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <Zap size={15} /> Telemetría
                </CardTitle>
                <span className="sam-meta">
                    {telemetry.length}{' '}
                    {telemetry.length === 1 ? 'medidor' : 'medidores'}
                </span>
            </CardHeader>
            <CardContent className="p-0">
                {telemetry.length === 0 ? (
                    <p className="px-4 py-5 text-sm text-fg-3">
                        Esta unidad aún no reporta telemetría (velocidad,
                        odómetro, combustible). Aparecerá en cuanto el equipo
                        empiece a transmitir.
                    </p>
                ) : (
                    <ul className="divide-y divide-border">
                        {telemetry.map((entry) => {
                            const Icon = TELEMETRY_ICONS[entry.type] ?? Zap;
                            const { value, unit } = telemetryValue(entry.data);

                            return (
                                <li
                                    key={entry.type}
                                    className="flex items-center gap-3 px-4 py-2.5"
                                >
                                    <Icon
                                        size={13}
                                        strokeWidth={1.75}
                                        className="shrink-0 text-fg-3"
                                        aria-hidden="true"
                                    />
                                    <span className="w-32 shrink-0 text-xs text-fg-2">
                                        {entry.label}
                                    </span>
                                    <span
                                        className={cn(
                                            'flex-1 font-mono text-sm tabular-nums',
                                            entry.stale
                                                ? 'text-fg-3'
                                                : 'text-fg-1',
                                        )}
                                        title={
                                            entry.stale
                                                ? 'Lectura antigua: sin velocidad reciente'
                                                : undefined
                                        }
                                    >
                                        {value}
                                        {unit && (
                                            <span className="ml-1 text-2xs text-fg-3">
                                                {unit}
                                            </span>
                                        )}
                                    </span>
                                    <span
                                        title={formatDateTime(entry.recordedAt)}
                                    >
                                        <RelativeTime
                                            minutes={minutesSince(
                                                entry.recordedAt,
                                            )}
                                        />
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
