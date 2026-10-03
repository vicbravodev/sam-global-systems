import { ShieldAlert } from 'lucide-react';
import { RelativeTime } from '@/components/sam/relative-time';
import {
    RISK_LEVEL_LABELS,
    RiskGauge,
    TREND_LABELS,
    resolveRiskLevel,
} from '@/components/sam/risk-gauge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { minutesSince } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { DriverActivityPoint, DriverDetail } from '@/types/drivers';

const TREND_TONE: Record<string, string> = {
    deteriorating: 'text-severity-critical',
    improving: 'text-severity-low',
    stable: 'text-fg-2',
    baseline: 'text-fg-3',
};

function ActivityBars({ points }: { points: DriverActivityPoint[] }) {
    const max = Math.max(1, ...points.map((p) => p.count));
    const total = points.reduce((sum, p) => sum + p.count, 0);

    return (
        <div className="flex flex-col gap-1.5">
            <div className="flex items-baseline justify-between">
                <span className="text-2xs font-semibold tracking-caps text-fg-3 uppercase">
                    Eventos · {points.length} días
                </span>
                <span className="font-mono text-2xs text-fg-2 tabular-nums">
                    {total} en total
                </span>
            </div>
            <div
                className="flex h-14 items-end gap-px"
                role="img"
                aria-label={`${total} eventos en los últimos ${points.length} días`}
            >
                {points.map((point, index) => {
                    const last = index === points.length - 1;
                    const height =
                        point.count === 0
                            ? 2
                            : Math.max(4, (point.count / max) * 56);

                    return (
                        <span
                            key={point.date}
                            title={`${formatDate(point.date)}: ${point.count}`}
                            className={cn(
                                'flex-1 rounded-t-[3px] transition-[height] duration-500 ease-(--ease-out)',
                                point.count === 0
                                    ? 'bg-surface-3'
                                    : last
                                      ? 'bg-accent'
                                      : 'bg-primary/55',
                            )}
                            style={{ height }}
                        />
                    );
                })}
            </div>
            <div className="flex justify-between text-3xs text-fg-3">
                <span>{formatDate(points[0]?.date)}</span>
                <span>hoy</span>
            </div>
        </div>
    );
}

export function RiskCard({
    risk,
    activity,
}: {
    risk: DriverDetail['riskProfile'];
    activity: DriverActivityPoint[];
}) {
    const level = resolveRiskLevel(risk?.riskLevel, risk?.riskScore ?? null);
    const delta =
        risk?.previousScore !== null &&
        risk?.previousScore !== undefined &&
        risk.riskScore !== null
            ? risk.riskScore - risk.previousScore
            : null;

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <ShieldAlert size={15} /> Perfil de riesgo
                </CardTitle>
                {risk?.lastCalculatedAt && (
                    <span className="sam-meta">
                        calculado{' '}
                        <RelativeTime
                            minutes={minutesSince(risk.lastCalculatedAt)}
                        />
                    </span>
                )}
            </CardHeader>
            <CardContent className="p-4">
                {risk === null || risk.riskScore === null ? (
                    <p className="text-sm text-fg-3">
                        Sin perfil de riesgo todavía. Se calcula cada noche con
                        la actividad de manejo del conductor (incidentes,
                        maniobras bruscas, alertas de fatiga) y aparece aquí en
                        cuanto haya eventos.
                    </p>
                ) : (
                    <div className="grid gap-5 md:grid-cols-[auto_1fr]">
                        <div className="flex flex-col items-center gap-2">
                            <RiskGauge
                                score={risk.riskScore}
                                level={risk.riskLevel}
                            />
                            {risk.trend && (
                                <span
                                    className={cn(
                                        'text-2xs font-medium',
                                        TREND_TONE[risk.trend] ?? 'text-fg-3',
                                    )}
                                >
                                    {TREND_LABELS[risk.trend] ?? risk.trend}
                                    {delta !== null && delta !== 0 && (
                                        <span className="ml-1 font-mono tabular-nums">
                                            ({delta > 0 ? '+' : ''}
                                            {delta.toFixed(0)})
                                        </span>
                                    )}
                                </span>
                            )}
                            {level && (
                                <span className="text-3xs text-fg-3">
                                    ventana {risk.windowDays ?? 30} días ·{' '}
                                    {RISK_LEVEL_LABELS[level].toLowerCase()}
                                </span>
                            )}
                        </div>
                        <div className="flex min-w-0 flex-col gap-4">
                            <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                {[
                                    [
                                        'Incidentes',
                                        risk.incidentsCount,
                                        risk.incidentsCount > 0,
                                    ],
                                    [
                                        'Maniobras bruscas',
                                        risk.harshEventsCount,
                                        false,
                                    ],
                                    [
                                        'Alertas de fatiga',
                                        risk.fatigueFlagsCount,
                                        risk.fatigueFlagsCount > 0,
                                    ],
                                    [
                                        'Eventos severos',
                                        risk.severeEventsCount,
                                        risk.severeEventsCount > 0,
                                    ],
                                ].map(([label, value, hot]) => (
                                    <div
                                        key={String(label)}
                                        className="rounded-md border border-border bg-surface-2 px-3 py-2"
                                    >
                                        <dt className="text-3xs text-fg-3">
                                            {label}
                                        </dt>
                                        <dd
                                            className={cn(
                                                'font-sans text-lg font-semibold',
                                                hot
                                                    ? 'text-severity-high'
                                                    : 'text-fg-1',
                                            )}
                                        >
                                            {value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                            {activity.length > 1 && (
                                <ActivityBars points={activity} />
                            )}
                        </div>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
