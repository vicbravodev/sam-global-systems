import { cn } from '@/lib/utils';
import { ChangeBadge } from './change-badge';
import {
    AGGREGATION_HINT,
    formatMetricValue,
    metricCopy,
} from './metric-catalog';
import { TrendChart } from './trend-chart';
import type { MetricRow } from './types';

interface Props {
    metric: MetricRow;
    against: string;
    color?: string;
    /** Sin gráfica: sólo cifra, frase y variación (métricas secundarias). */
    compact?: boolean;
    className?: string;
}

/**
 * Una métrica contada para alguien sin contexto técnico: nombre, frase de
 * qué significa, la cifra del periodo, cómo cambió y su tendencia diaria.
 */
export function MetricPanel({
    metric,
    against,
    color,
    compact = false,
    className,
}: Props) {
    const copy = metricCopy(metric);

    return (
        <div
            className={cn(
                'flex min-w-0 flex-col gap-3 bg-surface-1 p-4',
                className,
            )}
        >
            <div className="flex flex-col gap-0.5">
                <h3 className="text-sm font-medium text-fg-1">{copy.title}</h3>
                {copy.explain && (
                    <p className="text-xs text-fg-3">{copy.explain}</p>
                )}
            </div>
            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <span className="text-2xl font-semibold tracking-tight text-fg-1 tabular-nums">
                    {formatMetricValue(metric.value, metric.unit)}
                </span>
                <span className="text-2xs text-fg-3">
                    {AGGREGATION_HINT[metric.aggregation]}
                </span>
            </div>
            <ChangeBadge
                value={metric.value}
                previous={metric.previous}
                unit={metric.unit}
                better={copy.better}
                against={against}
            />
            {!compact && (
                <TrendChart
                    points={metric.series}
                    unit={metric.unit}
                    label={copy.title}
                    color={color}
                />
            )}
        </div>
    );
}
