import { Head, router, usePage } from '@inertiajs/react';
import { BarChart3, Download, FileBarChart2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { toast } from 'sonner';
import { SparkArea } from '@/components/sam/charts';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { useBroadcastReload } from '@/hooks/use-team-broadcasts';
import {
    formatDate,
    formatDateTime,
    formatNumber,
    formatPercent,
} from '@/lib/format';
import { kpiLabel, reportStatusLabel } from '@/lib/labels';
import { postJson, readErrorMessage } from '@/lib/sam-fetch';
import { cn } from '@/lib/utils';

interface OverviewProp {
    periodStart: string | null;
    periodEnd: string | null;
    data: Record<string, unknown> | null;
}

type MetricGroup = 'incidents' | 'ai' | 'decisions' | 'usage';

interface MetricRow {
    code: string;
    /** Nombre del catálogo metric_definitions (es), si existe. */
    name: string | null;
    group: MetricGroup;
    unit: string | null;
    /** sum = actividad del periodo, avg = promedio diario, latest = nivel actual. */
    aggregation: 'sum' | 'avg' | 'latest';
    value: number | null;
    series: { date: string; value: number }[];
}

interface ReportRow {
    id: number;
    code: string;
    name: string;
    description: string | null;
    reportType: string;
}

interface ExecutionRow {
    id: number;
    reportName: string | null;
    status: string | null;
    format: string;
    error: string | null;
    finishedAt: string | null;
    downloadable: boolean;
}

interface AnalyticsPageProps {
    overview: OverviewProp | null;
    period: number;
    periods: number[];
    metrics: MetricRow[];
    reports: ReportRow[];
    executions: ExecutionRow[];
    formats: string[];
    canGenerate: boolean;
}

const TABS = [
    { key: 'kpis', label: 'KPIs' },
    { key: 'reports', label: 'Reportes' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

const STATUS_COLOR: Record<string, string> = {
    completed: 'text-severity-low',
    failed: 'text-severity-critical',
    running: 'text-severity-medium',
    pending: 'text-severity-high',
    expired: 'text-fg-3',
};

const DOWNLOAD_FORMATS = ['pdf', 'xlsx', 'csv', 'json'];

const GROUP_LABELS: Record<MetricGroup, string> = {
    incidents: 'Incidentes',
    ai: 'Inteligencia artificial',
    decisions: 'Decisiones',
    usage: 'Uso de la plataforma',
};

const AGGREGATION_HINT: Record<MetricRow['aggregation'], string> = {
    sum: 'Total del periodo',
    avg: 'Promedio diario',
    latest: 'Valor más reciente',
};

/** Unidades de proporción (0..1) que se muestran como porcentaje. */
const RATIO_UNITS = new Set(['ratio', 'score', 'rate']);

function formatMetric(value: number | null, unit: string | null): string {
    if (value === null) {
        return '—';
    }

    if (unit !== null && RATIO_UNITS.has(unit)) {
        return formatPercent(value);
    }

    if (unit === 'minutes') {
        return `${formatNumber(value, { maximumFractionDigits: 1 })} min`;
    }

    return formatNumber(value, { maximumFractionDigits: 1 });
}

/** Unidad implícita de las claves del resumen del tenant. */
function overviewUnit(key: string): string | null {
    if (key.endsWith('_rate')) {
        return 'ratio';
    }

    if (key.endsWith('_minutes')) {
        return 'minutes';
    }

    return null;
}

function OverviewCards({
    overview,
    action,
}: {
    overview: OverviewProp | null;
    action?: ReactNode;
}) {
    if (overview === null || overview.data === null) {
        return (
            <EmptyState
                icon={BarChart3}
                title="Todavía no hay resumen del periodo"
                description="Las métricas se calculan automáticamente cada noche con la actividad de tu operación. En cuanto haya datos, aparecerán aquí."
                action={action}
            />
        );
    }

    const entries = Object.entries(overview.data).filter(
        ([, value]) => typeof value === 'number' || typeof value === 'string',
    );

    return (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            {entries.slice(0, 12).map(([key, value]) => (
                <div
                    key={key}
                    className="rounded-md border border-border bg-surface-1 p-3"
                >
                    <div className="text-2xs tracking-label text-fg-3">
                        {kpiLabel(key)}
                    </div>
                    <div className="text-xl font-semibold text-fg-1 tabular-nums">
                        {typeof value === 'number'
                            ? formatMetric(value, overviewUnit(key))
                            : String(value)}
                    </div>
                </div>
            ))}
        </div>
    );
}

function MetricsTable({ metrics }: { metrics: MetricRow[] }) {
    const groups = (Object.keys(GROUP_LABELS) as MetricGroup[])
        .map((group) => ({
            group,
            rows: metrics.filter((metric) => metric.group === group),
        }))
        .filter((entry) => entry.rows.length > 0);

    return (
        <table className="w-full text-left text-xs">
            <thead className="text-2xs tracking-label text-fg-3">
                <tr>
                    <th className="py-1.5 pr-4 font-medium">Métrica</th>
                    <th className="py-1.5 pr-4 font-medium">Valor</th>
                    <th className="hidden py-1.5 pr-4 font-medium sm:table-cell">
                        Tendencia diaria
                    </th>
                </tr>
            </thead>
            {groups.map(({ group, rows }) => (
                <tbody key={group}>
                    <tr>
                        <th
                            colSpan={3}
                            className="pt-4 pb-1 text-2xs font-semibold tracking-caps text-fg-3 uppercase"
                        >
                            {GROUP_LABELS[group]}
                        </th>
                    </tr>
                    {rows.map((metric) => (
                        <tr
                            key={metric.code}
                            className="border-t border-border/50 text-fg-2"
                        >
                            <td className="py-2 pr-4 text-fg-1">
                                {kpiLabel(metric.code, metric.name)}
                            </td>
                            <td className="py-2 pr-4">
                                <div className="font-semibold text-fg-1 tabular-nums">
                                    {formatMetric(metric.value, metric.unit)}
                                </div>
                                <div className="text-2xs text-fg-3">
                                    {AGGREGATION_HINT[metric.aggregation]}
                                </div>
                            </td>
                            <td className="hidden w-48 py-2 pr-4 sm:table-cell">
                                {metric.series.length > 1 ? (
                                    <SparkArea
                                        data={metric.series.map(
                                            (point) => point.value,
                                        )}
                                        width={180}
                                        height={32}
                                        aria-label={`Tendencia de ${kpiLabel(metric.code, metric.name)}`}
                                    />
                                ) : (
                                    <span className="text-2xs text-fg-3">
                                        Un solo día con datos
                                    </span>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            ))}
        </table>
    );
}

function PeriodPicker({
    period,
    periods,
}: {
    period: number;
    periods: number[];
}) {
    return (
        <div
            className="flex gap-0.5 rounded-md border border-border bg-surface-2 p-0.5"
            role="group"
            aria-label="Periodo"
        >
            {periods.map((days) => (
                <button
                    key={days}
                    type="button"
                    aria-pressed={period === days}
                    onClick={() =>
                        router.reload({
                            data: { period: days },
                            only: ['metrics', 'period'],
                        })
                    }
                    className={cn(
                        'cursor-pointer rounded-sm px-2.5 py-1 text-xs font-medium',
                        period === days
                            ? 'bg-surface-1 text-fg-1 shadow-xs'
                            : 'text-fg-3 hover:text-fg-1',
                    )}
                >
                    {days} días
                </button>
            ))}
        </div>
    );
}

function KpisTab({
    overview,
    metrics,
    period,
    periods,
    hasReports,
    onShowReports,
}: {
    overview: OverviewProp | null;
    metrics: MetricRow[];
    period: number;
    periods: number[];
    hasReports: boolean;
    onShowReports: () => void;
}) {
    // B2: cuando no hay ni resumen ni KPIs, las dos cards mostraban
    // empty-states casi idénticos apilados. Fusionar en uno solo.
    const noOverview = overview === null || overview.data === null;

    if (noOverview && metrics.length === 0 && period === periods[1]) {
        return (
            <Card>
                <CardContent className="py-2">
                    <EmptyState
                        icon={BarChart3}
                        title="Todavía no hay métricas para este periodo"
                        description="Los KPIs y el resumen del tenant se calculan automáticamente cada noche con la actividad de tu operación. En cuanto haya datos, aparecerán aquí; mientras tanto, el panel muestra la actividad en vivo."
                        action={
                            hasReports ? (
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
                </CardContent>
            </Card>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <Card>
                <CardHeader>
                    <CardTitle className="text-sm uppercase">
                        Resumen del tenant
                        {overview?.periodStart && (
                            <span className="ml-2 font-normal text-fg-3 normal-case">
                                {formatDate(overview.periodStart)}
                                {overview.periodEnd &&
                                    ` — ${formatDate(overview.periodEnd)}`}
                            </span>
                        )}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <OverviewCards
                        overview={overview}
                        action={
                            hasReports ? (
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
                </CardContent>
            </Card>

            <Card>
                <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
                    <CardTitle className="text-sm uppercase">
                        Indicadores de los últimos {period} días
                    </CardTitle>
                    <PeriodPicker period={period} periods={periods} />
                </CardHeader>
                <CardContent>
                    {metrics.length === 0 ? (
                        <EmptyState
                            title="Sin indicadores en este periodo"
                            description="Los KPIs se calculan automáticamente cada noche. Prueba un periodo más largo o vuelve mañana."
                        />
                    ) : (
                        <MetricsTable metrics={metrics} />
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

function ReportsTab({
    reports,
    executions,
    canGenerate,
}: {
    reports: ReportRow[];
    executions: ExecutionRow[];
    canGenerate: boolean;
}) {
    const page = usePage();
    const teamSlug =
        (
            page.props as unknown as {
                currentTeam?: { slug?: string | null } | null;
            }
        ).currentTeam?.slug ?? null;

    const [generating, setGenerating] = useState<string | null>(null);

    useBroadcastReload({ 'report.ready': ['executions'] }, { debounceMs: 300 });

    const generate = async (report: ReportRow, format: string) => {
        if (teamSlug === null) {
            return;
        }

        setGenerating(`${report.id}:${format}`);

        try {
            const response = await postJson(
                `/${teamSlug}/analytics/reports/${report.id}/generate`,
                { format },
            );

            if (response.ok || response.status === 202) {
                toast.success(
                    `Generando ${report.name} (${format.toUpperCase()})…`,
                );
                // The row shows up as running now; `report.ready` swaps it
                // for the finished one.
                router.reload({ only: ['executions'] });
            } else if (response.status === 403) {
                toast.error('No tienes permisos para generar reportes.');
            } else {
                toast.error(
                    (await readErrorMessage(response)) ??
                        'No se pudo generar el reporte.',
                );
            }
        } catch {
            toast.error('Error de red. Vuelve a intentarlo.');
        } finally {
            setGenerating(null);
        }
    };

    return (
        <div className="flex flex-col gap-4">
            <Card>
                <CardHeader>
                    <CardTitle className="text-sm uppercase">
                        Reportes disponibles ({reports.length})
                    </CardTitle>
                </CardHeader>
                <CardContent className="flex flex-col gap-2">
                    {reports.length === 0 ? (
                        <EmptyState
                            icon={FileBarChart2}
                            title="Todavía no hay reportes configurados"
                            description="Aquí verás reportes descargables con la actividad de tu operación: incidentes, flota y consumo. El equipo de SAM los configura contigo. Escríbenos y los activamos para tu cuenta."
                        />
                    ) : (
                        reports.map((report) => (
                            <div
                                key={report.id}
                                className="flex flex-wrap items-center gap-2 rounded-md border border-border p-2.5 text-xs"
                            >
                                <FileBarChart2
                                    size={14}
                                    className="text-fg-3"
                                />
                                <span className="font-medium text-fg-1">
                                    {report.name}
                                </span>
                                <span className="text-fg-3">
                                    {report.description ?? report.reportType}
                                </span>
                                {canGenerate && (
                                    <span className="ml-auto flex gap-1">
                                        {DOWNLOAD_FORMATS.map((format) => (
                                            <Button
                                                key={format}
                                                size="sm"
                                                variant="outline"
                                                disabled={
                                                    generating ===
                                                    `${report.id}:${format}`
                                                }
                                                onClick={() =>
                                                    generate(report, format)
                                                }
                                            >
                                                {format.toUpperCase()}
                                            </Button>
                                        ))}
                                    </span>
                                )}
                            </div>
                        ))
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="text-sm uppercase">
                        Ejecuciones recientes ({executions.length})
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {executions.length === 0 ? (
                        <p className="text-xs text-fg-3">
                            Aún no has generado ningún reporte. Cuando generes
                            uno, aparecerá aquí listo para descargar en el
                            formato que elijas.
                        </p>
                    ) : (
                        <table className="w-full text-left text-xs">
                            <thead className="text-2xs text-fg-3 uppercase">
                                <tr>
                                    <th className="py-1.5 pr-4">#</th>
                                    <th className="py-1.5 pr-4">Reporte</th>
                                    <th className="py-1.5 pr-4">Formato</th>
                                    <th className="py-1.5 pr-4">Estado</th>
                                    <th className="py-1.5 pr-4">Terminado</th>
                                    <th className="py-1.5" />
                                </tr>
                            </thead>
                            <tbody>
                                {executions.map((execution) => (
                                    <tr
                                        key={execution.id}
                                        className="border-t border-border/50 text-fg-2"
                                    >
                                        <td className="py-2 pr-4 font-mono text-2xs">
                                            {execution.id}
                                        </td>
                                        <td className="py-2 pr-4 text-fg-1">
                                            {execution.reportName ?? '—'}
                                        </td>
                                        <td className="py-2 pr-4 font-mono text-2xs uppercase">
                                            {execution.format}
                                        </td>
                                        <td
                                            className={`py-2 pr-4 ${STATUS_COLOR[execution.status ?? ''] ?? 'text-fg-3'}`}
                                        >
                                            {reportStatusLabel(
                                                execution.status,
                                            )}
                                            {execution.status === 'failed' && (
                                                <div className="max-w-xs text-2xs text-fg-3">
                                                    {execution.error ??
                                                        'Sin detalle del error. Vuelve a generarlo o escríbenos.'}
                                                </div>
                                            )}
                                        </td>
                                        <td className="py-2 pr-4 font-mono text-2xs whitespace-nowrap">
                                            {formatDateTime(
                                                execution.finishedAt,
                                            )}
                                        </td>
                                        <td className="py-2 text-right">
                                            {execution.downloadable &&
                                                teamSlug && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        asChild
                                                    >
                                                        <a
                                                            href={`/${teamSlug}/analytics/executions/${execution.id}/download`}
                                                        >
                                                            <Download
                                                                size={12}
                                                            />
                                                            Descargar
                                                        </a>
                                                    </Button>
                                                )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

export default function AnalyticsIndex() {
    const page = usePage();
    const props = page.props as unknown as AnalyticsPageProps;
    const [tab, setTab] = useState<TabKey>('kpis');

    return (
        <>
            <Head title="Analítica" />
            <div className="flex flex-col gap-4 p-5">
                <PageHeader
                    title="Analítica"
                    description="KPIs operativos del tenant y reportes descargables."
                />

                <div className="flex flex-wrap gap-1 border-b border-border">
                    {TABS.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => setTab(item.key)}
                            className={`px-3 py-2 text-sm transition-colors ${
                                tab === item.key
                                    ? 'border-b-2 border-primary font-medium text-fg-1'
                                    : 'text-fg-3 hover:text-fg-1'
                            }`}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>

                {tab === 'kpis' && (
                    <KpisTab
                        overview={props.overview}
                        metrics={props.metrics}
                        period={props.period}
                        periods={props.periods}
                        hasReports={props.reports.length > 0}
                        onShowReports={() => setTab('reports')}
                    />
                )}
                {tab === 'reports' && (
                    <ReportsTab
                        reports={props.reports}
                        executions={props.executions}
                        canGenerate={props.canGenerate}
                    />
                )}
            </div>
        </>
    );
}

AnalyticsIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Analítica',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/analytics`
                : '/analytics',
        },
    ],
});
