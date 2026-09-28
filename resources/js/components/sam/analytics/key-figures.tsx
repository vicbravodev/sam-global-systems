import { SparkArea } from '@/components/sam/charts';
import { Kpi, KpiStrip } from '@/components/sam/kpi-strip';
import { formatNumber, formatPercent } from '@/lib/format';
import { ChangeBadge } from './change-badge';
import { formatMetricValue, metricCopy } from './metric-catalog';
import type { FleetLevel, MetricRow } from './types';

interface Props {
    metrics: Map<string, MetricRow>;
    fleet: FleetLevel;
    against: string;
}

function Spark({ metric, color }: { metric?: MetricRow; color: string }) {
    if (!metric || metric.series.length < 2) {
        return null;
    }

    return (
        <SparkArea
            data={metric.series.map((point) => point.value)}
            height={32}
            color={color}
            aria-label={`Tendencia de ${metricCopy(metric).title}`}
        />
    );
}

function Change({ metric, against }: { metric?: MetricRow; against: string }) {
    if (!metric) {
        return null;
    }

    return (
        <ChangeBadge
            value={metric.value}
            previous={metric.previous}
            unit={metric.unit}
            better={metricCopy(metric).better}
            against={against}
        />
    );
}

/**
 * Las cuatro cifras que un supervisor mira primero: cuánto pasó, cuánto
 * tardamos, qué tan bien decidió la IA y cuánta flota está bajo vigilancia.
 */
export function KeyFigures({ metrics, fleet, against }: Props) {
    const total = metrics.get('incidents_total');
    const resolved = metrics.get('incidents_resolved');
    const open = metrics.get('incidents_open');
    const mttr = metrics.get('incidents_mttr_minutes');
    const accuracy = metrics.get('ai_accuracy_rate');
    const override = metrics.get('ai_human_override_rate');

    const resolvedShare =
        total?.value &&
        resolved?.value !== null &&
        resolved?.value !== undefined
            ? resolved.value / total.value
            : null;

    return (
        <KpiStrip cols={4}>
            <Kpi
                label="Incidentes nuevos"
                value={formatMetricValue(total?.value ?? null, 'count')}
                sub={
                    <div className="flex flex-col gap-1">
                        <Change metric={total} against={against} />
                        <span>
                            {resolvedShare !== null
                                ? `${formatPercent(resolvedShare, { digits: 0 })} ya resueltos`
                                : 'Sin incidentes en el periodo'}
                            {open?.value
                                ? ` · ${formatNumber(open.value)} siguen abiertos`
                                : ''}
                        </span>
                    </div>
                }
                sparkline={<Spark metric={total} color="var(--chart-1)" />}
            />
            <Kpi
                label="Tiempo medio de resolución"
                value={formatMetricValue(mttr?.value ?? null, 'minutes')}
                sub={
                    <div className="flex flex-col gap-1">
                        <Change metric={mttr} against={against} />
                        <span>desde que se abre hasta que se cierra</span>
                    </div>
                }
                sparkline={<Spark metric={mttr} color="var(--chart-3)" />}
            />
            <Kpi
                label="Acierto de la IA"
                value={formatMetricValue(accuracy?.value ?? null, 'ratio')}
                sub={
                    <div className="flex flex-col gap-1">
                        <Change metric={accuracy} against={against} />
                        <span>
                            {override?.value !== null &&
                            override?.value !== undefined
                                ? `un operador corrigió el ${formatPercent(override.value, { digits: 0 })}`
                                : 'decisiones que nadie tuvo que corregir'}
                        </span>
                    </div>
                }
                sparkline={<Spark metric={accuracy} color="var(--chart-2)" />}
            />
            <Kpi
                label="Activos vigilados"
                value={formatNumber(fleet.monitored)}
                sub={
                    <div className="flex flex-col gap-1">
                        <span className="text-fg-2">ahora mismo</span>
                        <span>
                            de {formatNumber(fleet.total)}{' '}
                            {fleet.total === 1 ? 'unidad' : 'unidades'} en tu
                            flota
                        </span>
                    </div>
                }
            />
        </KpiStrip>
    );
}
