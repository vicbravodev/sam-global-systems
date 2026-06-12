import { Head, router, usePage } from '@inertiajs/react';
import { BarChart3, Download, FileBarChart2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { postJson, readErrorMessage } from '@/lib/sam-fetch';

interface OverviewProp {
    periodStart: string | null;
    periodEnd: string | null;
    data: Record<string, unknown> | null;
}

interface KpiRow {
    id: number;
    code: string;
    value: number | null;
    unit: string | null;
    periodType: string;
    periodStart: string | null;
    dimensionType: string | null;
    dimensionReference: string | null;
    calculatedAt: string | null;
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
    kpis: KpiRow[];
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

function formatValue(value: number | null, unit: string | null): string {
    if (value === null) {
        return '—';
    }

    const formatted = Number.isInteger(value)
        ? value.toLocaleString('es')
        : value.toFixed(2);

    return unit ? `${formatted} ${unit}` : formatted;
}

function OverviewCards({ overview }: { overview: OverviewProp | null }) {
    if (overview === null || overview.data === null) {
        return (
            <EmptyState
                icon={BarChart3}
                title="Todavía no hay resumen del periodo"
                description="Las métricas se calculan automáticamente cada noche con la actividad de tu operación. En cuanto haya datos, aparecerán aquí."
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
                    className="rounded-[6px] border border-border bg-surface-1 p-3"
                >
                    <div className="text-[11px] text-fg-3 uppercase">
                        {key.replaceAll('_', ' ')}
                    </div>
                    <div className="text-[20px] font-semibold text-fg-1 tabular-nums">
                        {typeof value === 'number'
                            ? value.toLocaleString('es')
                            : String(value)}
                    </div>
                </div>
            ))}
        </div>
    );
}

function KpisTab({
    overview,
    kpis,
}: {
    overview: OverviewProp | null;
    kpis: KpiRow[];
}) {
    return (
        <div className="flex flex-col gap-4">
            <Card>
                <CardHeader>
                    <CardTitle className="text-[13px] uppercase">
                        Resumen del tenant
                        {overview?.periodStart && (
                            <span className="ml-2 font-normal text-fg-3 normal-case">
                                {new Date(
                                    overview.periodStart,
                                ).toLocaleDateString('es')}
                                {overview.periodEnd &&
                                    ` — ${new Date(overview.periodEnd).toLocaleDateString('es')}`}
                            </span>
                        )}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <OverviewCards overview={overview} />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="text-[13px] uppercase">
                        KPIs recientes ({kpis.length})
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {kpis.length === 0 ? (
                        <EmptyState
                            title="Todavía no hay KPIs calculados"
                            description="Se calculan automáticamente cada noche. Mientras tanto, el panel muestra la actividad en vivo."
                        />
                    ) : (
                        <table className="w-full text-left text-[12px]">
                            <thead className="text-[11px] text-fg-3 uppercase">
                                <tr>
                                    <th className="py-1.5 pr-4">KPI</th>
                                    <th className="py-1.5 pr-4">Valor</th>
                                    <th className="py-1.5 pr-4">Periodo</th>
                                    <th className="py-1.5 pr-4">Dimensión</th>
                                    <th className="py-1.5 pr-4">Calculado</th>
                                </tr>
                            </thead>
                            <tbody>
                                {kpis.map((kpi) => (
                                    <tr
                                        key={kpi.id}
                                        className="border-t border-border/50 text-fg-2"
                                    >
                                        <td className="py-2 pr-4 font-mono text-[11px]">
                                            {kpi.code}
                                        </td>
                                        <td className="py-2 pr-4 font-semibold text-fg-1 tabular-nums">
                                            {formatValue(kpi.value, kpi.unit)}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {kpi.periodType}
                                            {kpi.periodStart &&
                                                ` · ${new Date(kpi.periodStart).toLocaleDateString('es')}`}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {kpi.dimensionType
                                                ? `${kpi.dimensionType}${kpi.dimensionReference ? ` #${kpi.dimensionReference}` : ''}`
                                                : 'global'}
                                        </td>
                                        <td className="py-2 pr-4 font-mono text-[11px] whitespace-nowrap">
                                            {kpi.calculatedAt
                                                ? new Date(
                                                      kpi.calculatedAt,
                                                  ).toLocaleString('es')
                                                : '—'}
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
                setTimeout(() => router.reload({ only: ['executions'] }), 1200);
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
                    <CardTitle className="text-[13px] uppercase">
                        Reportes disponibles ({reports.length})
                    </CardTitle>
                </CardHeader>
                <CardContent className="flex flex-col gap-2">
                    {reports.length === 0 ? (
                        <p className="text-[12px] text-fg-3">
                            Sin definiciones de reporte activas.
                        </p>
                    ) : (
                        reports.map((report) => (
                            <div
                                key={report.id}
                                className="flex flex-wrap items-center gap-2 rounded-[6px] border border-border p-2.5 text-[12px]"
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
                    <CardTitle className="text-[13px] uppercase">
                        Ejecuciones recientes ({executions.length})
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {executions.length === 0 ? (
                        <p className="text-[12px] text-fg-3">
                            Aún sin ejecuciones.
                        </p>
                    ) : (
                        <table className="w-full text-left text-[12px]">
                            <thead className="text-[11px] text-fg-3 uppercase">
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
                                        <td className="py-2 pr-4 font-mono text-[11px]">
                                            {execution.id}
                                        </td>
                                        <td className="py-2 pr-4 text-fg-1">
                                            {execution.reportName ?? '—'}
                                        </td>
                                        <td className="py-2 pr-4 font-mono text-[11px] uppercase">
                                            {execution.format}
                                        </td>
                                        <td
                                            className={`py-2 pr-4 ${STATUS_COLOR[execution.status ?? ''] ?? 'text-fg-3'}`}
                                            title={execution.error ?? ''}
                                        >
                                            {execution.status}
                                        </td>
                                        <td className="py-2 pr-4 font-mono text-[11px] whitespace-nowrap">
                                            {execution.finishedAt
                                                ? new Date(
                                                      execution.finishedAt,
                                                  ).toLocaleString('es')
                                                : '—'}
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
                <div>
                    <h1 className="text-[16px] font-semibold text-fg-1">
                        Analítica
                    </h1>
                    <p className="text-[12px] text-fg-3">
                        KPIs operativos del tenant y reportes descargables.
                    </p>
                </div>

                <div className="flex flex-wrap gap-1 border-b border-border">
                    {TABS.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => setTab(item.key)}
                            className={`px-3 py-2 text-[13px] transition-colors ${
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
                    <KpisTab overview={props.overview} kpis={props.kpis} />
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
