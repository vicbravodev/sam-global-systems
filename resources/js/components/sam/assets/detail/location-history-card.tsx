import { History } from 'lucide-react';
import { MOVING_SPEED_KPH } from '@/components/sam/assets/detail/telemetry';
import { RelativeTime } from '@/components/sam/relative-time';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime, formatNumber } from '@/lib/format';
import { sourceLabel } from '@/lib/labels';
import { minutesSince } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { LocationHistoryEntry } from '@/types/assets';

export function LocationHistoryCard({
    history,
    windowHours,
}: {
    history: LocationHistoryEntry[];
    windowHours: number;
}) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <History size={15} /> Recorrido reciente
                </CardTitle>
                <span className="sam-meta">
                    últimas {windowHours} h · una posición por minuto
                </span>
            </CardHeader>
            <CardContent className="p-0">
                {history.length === 0 ? (
                    <p className="px-4 py-5 text-sm text-fg-3">
                        El recorrido se llenará en cuanto la unidad empiece a
                        reportar ubicación.
                    </p>
                ) : (
                    <div className="max-h-80 overflow-auto">
                        <table className="w-full border-collapse">
                            <thead>
                                <tr className="sam-caps sticky top-0 z-10 border-b border-border bg-surface-3">
                                    <th className="w-32 px-4 py-2 text-left">
                                        Cuándo
                                    </th>
                                    <th className="px-2.5 py-2 text-left">
                                        Ubicación
                                    </th>
                                    <th className="w-28 px-2.5 py-2 text-right">
                                        Velocidad
                                    </th>
                                    <th className="w-20 px-2.5 py-2 text-right">
                                        Rumbo
                                    </th>
                                    <th className="w-24 px-2.5 py-2 text-left">
                                        Fuente
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {history.map((entry) => (
                                    <tr
                                        key={entry.id}
                                        className="border-b border-border"
                                    >
                                        <td
                                            className="px-4 py-2"
                                            title={formatDateTime(
                                                entry.recordedAt,
                                            )}
                                        >
                                            <RelativeTime
                                                minutes={minutesSince(
                                                    entry.recordedAt,
                                                )}
                                            />
                                        </td>
                                        <td className="px-2.5 py-2">
                                            {entry.formattedLocation ? (
                                                <span className="text-xs text-fg-2">
                                                    {entry.formattedLocation}
                                                </span>
                                            ) : (
                                                <span className="font-mono text-2xs text-fg-2 tabular-nums">
                                                    {entry.latitude.toFixed(5)},{' '}
                                                    {entry.longitude.toFixed(5)}
                                                </span>
                                            )}
                                        </td>
                                        <td
                                            className={cn(
                                                'px-2.5 py-2 text-right font-mono text-2xs tabular-nums',
                                                entry.speed !== null &&
                                                    entry.speed >
                                                        MOVING_SPEED_KPH
                                                    ? 'text-fg-1'
                                                    : 'text-fg-3',
                                            )}
                                        >
                                            {entry.speed !== null
                                                ? `${formatNumber(entry.speed, { maximumFractionDigits: 0 })} km/h`
                                                : '—'}
                                        </td>
                                        <td className="px-2.5 py-2 text-right font-mono text-2xs text-fg-2 tabular-nums">
                                            {entry.heading !== null
                                                ? `${entry.heading}°`
                                                : '—'}
                                        </td>
                                        <td className="px-2.5 py-2 font-mono text-3xs text-fg-3">
                                            {sourceLabel(entry.source)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
