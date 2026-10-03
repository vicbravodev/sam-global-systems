import { Kpi, KpiStrip } from '@/components/sam';
import type { DashboardKpis } from '@/types/dashboard';
import { formatSlaClock, percentLabel } from './lib';

export const KPI_LABELS = [
    'Incidentes abiertos',
    'Críticos ahora',
    'SLA cumplido · 7 d',
    'Precisión IA · 7 d',
] as const;

export function KpiCards({ kpis }: { kpis: DashboardKpis }) {
    return (
        <KpiStrip className="shrink-0">
            <Kpi
                label="Incidentes abiertos"
                value={String(kpis.openIncidents.value)}
                delta={
                    kpis.openIncidents.deltaPct !== null
                        ? {
                              value: kpis.openIncidents.deltaPct,
                              invert: true,
                          }
                        : undefined
                }
                sub={
                    kpis.openIncidents.deltaPct === null
                        ? 'sin datos de ayer'
                        : undefined
                }
                sparkline={<Spark series={kpis.openIncidents.series} />}
            />
            <Kpi
                label="Críticos ahora"
                value={String(kpis.criticalOpen.value)}
                sub={`SLA promedio: ${formatSlaClock(kpis.criticalOpen.avgSlaRemainingSeconds)}`}
                sparkline={<Spark series={kpis.criticalOpen.series} />}
            />
            <Kpi
                label="SLA cumplido · 7 d"
                value={percentLabel(kpis.slaCompliance.value)}
                delta={
                    kpis.slaCompliance.deltaPp !== null
                        ? {
                              value: kpis.slaCompliance.deltaPp,
                              unit: 'pp',
                          }
                        : undefined
                }
                sub={
                    kpis.slaCompliance.deltaPp === null
                        ? 'sin comparativa previa'
                        : undefined
                }
            />
            <Kpi
                label="Precisión IA · 7 d"
                value={percentLabel(kpis.aiPrecision.value)}
                delta={
                    kpis.aiPrecision.deltaPp !== null
                        ? {
                              value: kpis.aiPrecision.deltaPp,
                              unit: 'pp',
                          }
                        : undefined
                }
                sub={
                    kpis.aiPrecision.deltaPp === null
                        ? 'sin comparativa previa'
                        : undefined
                }
            />
        </KpiStrip>
    );
}

interface SparkProps {
    series: number[];
}

/** Mini sparkline bajo el valor de un Kpi. */
function Spark({ series }: SparkProps) {
    const max = Math.max(...series);
    const min = Math.min(...series);

    // Sin al menos dos puntos y variación real, la línea es ruido decorativo.
    if (series.length < 2 || max === min) {
        return null;
    }

    const range = max - min || 1;
    const stepX = 90 / (series.length - 1);

    const points = series
        .map((value, index) => {
            const x = (index * stepX).toFixed(1);
            const y = (28 - ((value - min) / range) * 24).toFixed(1);

            return `${x},${y}`;
        })
        .join(' ');

    return (
        <svg
            className="opacity-55"
            width="90"
            height="30"
            viewBox="0 0 90 30"
            aria-hidden="true"
        >
            <polyline
                fill="none"
                stroke="var(--fg-3)"
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeLinejoin="round"
                points={points}
            />
        </svg>
    );
}
