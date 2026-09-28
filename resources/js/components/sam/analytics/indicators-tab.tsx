import { BarChart3, ChevronRight, FileBarChart2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { cn } from '@/lib/utils';
import { KeyFigures } from './key-figures';
import { byCode } from './metric-catalog';
import { MetricPanel } from './metric-panel';
import type { FleetLevel, MetricRow } from './types';

/** Métricas con gráfica por sección, en orden de lectura. */
const INCIDENT_CODES = [
    'incidents_total',
    'incidents_resolved',
    'incidents_mttr_minutes',
    'incidents_open',
];

const AI_MAIN_CODES = [
    'ai_accuracy_rate',
    'ai_total_evaluations',
    'ai_false_positive_rate',
    'decisions_human_review_rate',
];

/** Cifras de apoyo de la IA: sin gráfica, en una fila. */
const AI_DETAIL_CODES = [
    'ai_average_confidence',
    'ai_human_override_rate',
    'decisions_total',
    'ai_real_event_rate',
];

/** Duplicado histórico de ai_total_evaluations: nunca se muestra. */
const HIDDEN_CODES = new Set(['ai_evaluations_total']);

const USAGE_ORDER = [
    'ingested_events',
    'ai_calls',
    'outbound_notifications',
    'copilot_queries',
    'active_assets',
];

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section className="flex flex-col gap-3">
            <div>
                <h2 className="text-base font-semibold text-fg-1">{title}</h2>
                <p className="text-sm text-fg-3">{description}</p>
            </div>
            {children}
        </section>
    );
}

function PanelGrid({
    metrics,
    against,
    colors,
}: {
    metrics: MetricRow[];
    against: string;
    colors: string[];
}) {
    return (
        <div className="grid gap-px overflow-hidden rounded-lg border border-border bg-border md:grid-cols-2">
            {metrics.map((metric, i) => (
                <MetricPanel
                    key={metric.code}
                    metric={metric}
                    against={against}
                    color={colors[i % colors.length]}
                    className={cn(
                        metrics.length % 2 === 1 &&
                            i === metrics.length - 1 &&
                            'md:col-span-2',
                    )}
                />
            ))}
        </div>
    );
}

function pick(index: Map<string, MetricRow>, codes: string[]): MetricRow[] {
    return codes
        .map((code) => index.get(code))
        .filter((metric): metric is MetricRow => metric !== undefined);
}

interface Props {
    metrics: MetricRow[];
    fleet: FleetLevel;
    period: number;
    longestPeriod: number;
    onPeriod: (days: number) => void;
    hasReports: boolean;
    onShowReports: () => void;
}

export function IndicatorsTab({
    metrics,
    fleet,
    period,
    longestPeriod,
    onPeriod,
    hasReports,
    onShowReports,
}: Props) {
    const against = `los ${period} días anteriores`;

    if (metrics.length === 0) {
        return (
            <EmptyState
                icon={BarChart3}
                title={`Todavía no hay cifras de los últimos ${period} días`}
                description="Cada noche SAM resume la actividad del día: incidentes, tiempos de respuesta y trabajo de la IA. Las cifras aparecen aquí a partir del día siguiente a que tu operación empiece a registrar eventos."
                action={
                    period < longestPeriod ? (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => onPeriod(longestPeriod)}
                        >
                            Ver los últimos {longestPeriod} días
                        </Button>
                    ) : hasReports ? (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={onShowReports}
                        >
                            <FileBarChart2 size={13} />
                            Ver reportes disponibles
                        </Button>
                    ) : undefined
                }
            />
        );
    }

    const index = byCode(metrics);
    const known = new Set([
        ...INCIDENT_CODES,
        ...AI_MAIN_CODES,
        ...AI_DETAIL_CODES,
        ...USAGE_ORDER,
        ...HIDDEN_CODES,
    ]);

    // ai_total_evaluations lo escribe la evaluación de IA; si falta, su
    // gemelo del catálogo ocupa su lugar.
    const twin = index.get('ai_evaluations_total');

    if (!index.has('ai_total_evaluations') && twin) {
        index.set('ai_total_evaluations', twin);
    }

    const incidents = pick(index, INCIDENT_CODES);
    const aiMain = pick(index, AI_MAIN_CODES);
    const aiDetail = pick(index, AI_DETAIL_CODES);
    const usage = [
        ...pick(index, USAGE_ORDER),
        ...metrics.filter((metric) => !known.has(metric.code)),
    ];

    return (
        <div className="flex flex-col gap-8">
            <KeyFigures metrics={index} fleet={fleet} against={against} />

            {incidents.length > 0 && (
                <Section
                    title="Incidentes"
                    description="Cuántas situaciones hubo que atender y qué tan rápido se cerraron."
                >
                    <PanelGrid
                        metrics={incidents}
                        against={against}
                        colors={[
                            'var(--chart-1)',
                            'var(--chart-2)',
                            'var(--chart-3)',
                            'var(--chart-4)',
                        ]}
                    />
                </Section>
            )}

            {(aiMain.length > 0 || aiDetail.length > 0) && (
                <Section
                    title="Inteligencia artificial"
                    description="Cómo revisa la IA los eventos de tus unidades y cuándo pide ayuda a una persona."
                >
                    {aiMain.length > 0 && (
                        <PanelGrid
                            metrics={aiMain}
                            against={against}
                            colors={[
                                'var(--chart-2)',
                                'var(--chart-1)',
                                'var(--chart-5)',
                                'var(--chart-4)',
                            ]}
                        />
                    )}
                    {aiDetail.length > 0 && (
                        <div className="grid grid-cols-1 gap-px overflow-hidden rounded-lg border border-border bg-border sm:grid-cols-2 xl:grid-cols-4">
                            {aiDetail.map((metric) => (
                                <MetricPanel
                                    key={metric.code}
                                    metric={metric}
                                    against={against}
                                    compact
                                />
                            ))}
                        </div>
                    )}
                </Section>
            )}

            {usage.length > 0 && (
                <details className="group rounded-lg border border-border bg-surface-1">
                    <summary className="flex cursor-pointer list-none items-center gap-2 px-4 py-3 [&::-webkit-details-marker]:hidden">
                        <ChevronRight
                            className="size-4 shrink-0 text-fg-3 transition-transform group-open:rotate-90"
                            aria-hidden="true"
                        />
                        <span className="flex flex-col">
                            <span className="text-sm font-medium text-fg-1">
                                Uso de la plataforma
                            </span>
                            <span className="text-xs text-fg-3">
                                Eventos recibidos, consultas a la IA y avisos
                                enviados. Útil para revisar tu consumo.
                            </span>
                        </span>
                    </summary>
                    <div className="border-t border-border p-4">
                        <PanelGrid
                            metrics={usage}
                            against={against}
                            colors={['var(--chart-1)']}
                        />
                    </div>
                </details>
            )}
        </div>
    );
}
