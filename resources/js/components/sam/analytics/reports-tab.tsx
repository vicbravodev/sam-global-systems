import { router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Brain,
    Briefcase,
    CalendarClock,
    Clock,
    Download,
    FileBarChart2,
    FileClock,
    RotateCcw,
    Siren,
    Timer,
    Truck,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { toast } from 'sonner';
import { MetaChip } from '@/components/sam/meta-chip';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { useBroadcastReload } from '@/hooks/use-team-broadcasts';
import { formatDateTime } from '@/lib/format';
import { postJson, readErrorMessage } from '@/lib/sam-fetch';
import { relativeLabel } from '@/lib/time';
import type { Choice } from './choice-group';
import { ChoiceGroup } from './choice-group';
import { ReportStatus } from './report-status';
import type { ExecutionRow, ReportRow } from './types';

/** Formatos descargables, en orden de uso habitual. */
const FORMAT_CHOICES: Choice<string>[] = [
    { value: 'pdf', label: 'PDF', hint: 'Para leer, imprimir o compartir' },
    {
        value: 'xlsx',
        label: 'Excel',
        hint: 'Para trabajar los datos en una hoja de cálculo',
    },
    {
        value: 'csv',
        label: 'CSV',
        hint: 'Datos en texto plano para otros programas',
    },
    {
        value: 'json',
        label: 'JSON',
        hint: 'Para integraciones con otros sistemas',
    },
];

const FORMAT_LABEL: Record<string, string> = {
    ...Object.fromEntries(
        FORMAT_CHOICES.map((choice) => [choice.value, choice.label]),
    ),
    // Resultado sólo en pantalla (sin archivo que descargar).
    dashboard: 'En pantalla',
};

const REPORT_KIND: Record<string, { label: string; icon: LucideIcon }> = {
    operational: { label: 'Operación', icon: FileBarChart2 },
    executive: { label: 'Dirección', icon: Briefcase },
    sla: { label: 'Tiempos de atención', icon: Timer },
    ai_performance: { label: 'Inteligencia artificial', icon: Brain },
    incident_analysis: { label: 'Incidentes', icon: Siren },
    asset_risk: { label: 'Flota', icon: Truck },
    custom: { label: 'Personalizado', icon: FileBarChart2 },
};

const FREQUENCY_LABEL: Record<string, string> = {
    daily: 'Se genera solo cada día',
    weekly: 'Se genera solo cada semana',
    monthly: 'Se genera solo cada mes',
};

function SectionHead({
    title,
    description,
    aside,
}: {
    title: string;
    description: string;
    aside?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-end justify-between gap-2">
            <div>
                <h2 className="text-base font-semibold text-fg-1">{title}</h2>
                <p className="text-sm text-fg-3">{description}</p>
            </div>
            {aside}
        </div>
    );
}

function ReportCard({
    report,
    formats,
    canGenerate,
    busy,
    onGenerate,
}: {
    report: ReportRow;
    formats: Choice<string>[];
    canGenerate: boolean;
    busy: boolean;
    onGenerate: (format: string) => void;
}) {
    const [format, setFormat] = useState(formats[0]?.value ?? 'pdf');
    const kind = REPORT_KIND[report.reportType] ?? REPORT_KIND.custom;
    const Icon = kind.icon;

    return (
        <article className="flex min-w-0 flex-col gap-3 rounded-lg border border-border bg-surface-1 p-4">
            <div className="flex items-start gap-3">
                <div className="grid size-9 shrink-0 place-items-center rounded-md bg-surface-2 text-fg-2">
                    <Icon className="size-4" aria-hidden="true" />
                </div>
                <div className="flex min-w-0 flex-col gap-1">
                    <h3 className="text-sm font-medium text-fg-1">
                        {report.name}
                    </h3>
                    {report.description && (
                        <p className="text-xs text-fg-3">
                            {report.description}
                        </p>
                    )}
                    <div className="mt-1 flex flex-wrap gap-1.5">
                        <MetaChip>{kind.label}</MetaChip>
                        {report.frequency &&
                            FREQUENCY_LABEL[report.frequency] && (
                                <MetaChip>
                                    <CalendarClock
                                        className="size-3"
                                        aria-hidden="true"
                                    />
                                    {FREQUENCY_LABEL[report.frequency]}
                                </MetaChip>
                            )}
                    </div>
                </div>
            </div>
            {canGenerate && formats.length > 0 && (
                <div className="mt-auto flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3">
                    <ChoiceGroup
                        aria-label={`Formato de ${report.name}`}
                        options={formats}
                        value={format}
                        onChange={setFormat}
                        size="sm"
                    />
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => onGenerate(format)}
                        disabled={busy}
                    >
                        <FileBarChart2 size={13} />
                        {busy ? 'Generando…' : 'Generar'}
                    </Button>
                </div>
            )}
        </article>
    );
}

function ExecutionItem({
    execution,
    teamSlug,
    canRetry,
    busy,
    onRetry,
}: {
    execution: ExecutionRow;
    teamSlug: string | null;
    canRetry: boolean;
    busy: boolean;
    onRetry: () => void;
}) {
    const status = execution.status;
    const when =
        status === 'running' || status === 'pending'
            ? execution.requestedAt
            : (execution.finishedAt ?? execution.requestedAt);
    const whenPrefix =
        status === 'running' || status === 'pending'
            ? 'Iniciado'
            : status === 'failed'
              ? 'Falló'
              : 'Generado';

    return (
        <li className="flex flex-col gap-2 bg-surface-1 px-4 py-3 sm:flex-row sm:items-center sm:gap-4">
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-medium text-fg-1">
                        {execution.reportName ?? 'Reporte eliminado'}
                    </span>
                    <ReportStatus status={status} />
                </div>
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-2xs text-fg-3">
                    <MetaChip>
                        {FORMAT_LABEL[execution.format] ??
                            execution.format.toUpperCase()}
                    </MetaChip>
                    <span>
                        {execution.automatic
                            ? 'Automático'
                            : 'Pedido por tu equipo'}
                    </span>
                    {when && (
                        <span
                            title={formatDateTime(when)}
                            className="inline-flex items-center gap-1"
                        >
                            <Clock className="size-3" aria-hidden="true" />
                            {whenPrefix} {relativeLabel(when)}
                        </span>
                    )}
                </div>
                {status === 'failed' && (
                    <p className="text-xs text-severity-critical">
                        {execution.error ??
                            'No pudimos generar el archivo. Vuelve a intentarlo; si se repite, escríbenos.'}
                    </p>
                )}
                {status === 'expired' && (
                    <p className="text-xs text-fg-3">
                        El archivo ya no está disponible: se guardan sólo unos
                        días. Genéralo de nuevo si lo necesitas.
                    </p>
                )}
            </div>
            <div className="flex shrink-0 gap-2">
                {execution.downloadable && teamSlug && (
                    <Button size="sm" variant="outline" asChild>
                        <a
                            href={`/${teamSlug}/analytics/executions/${execution.id}/download`}
                        >
                            <Download size={13} />
                            Descargar
                        </a>
                    </Button>
                )}
                {(status === 'failed' || status === 'expired') && canRetry && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={onRetry}
                        disabled={busy}
                    >
                        <RotateCcw size={13} />
                        {status === 'failed'
                            ? 'Reintentar'
                            : 'Generar de nuevo'}
                    </Button>
                )}
            </div>
        </li>
    );
}

interface Props {
    reports: ReportRow[];
    executions: ExecutionRow[];
    formats: string[];
    canGenerate: boolean;
    teamSlug: string | null;
}

export function ReportsTab({
    reports,
    executions,
    formats,
    canGenerate,
    teamSlug,
}: Props) {
    const [generating, setGenerating] = useState<string | null>(null);

    useBroadcastReload({ 'report.ready': ['executions'] }, { debounceMs: 300 });

    const available = FORMAT_CHOICES.filter((choice) =>
        formats.includes(choice.value),
    );
    const reportsById = new Map(reports.map((report) => [report.id, report]));

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
                    `Generando «${report.name}» en ${FORMAT_LABEL[format] ?? format.toUpperCase()}. Aparecerá en el historial cuando esté listo.`,
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
        <div className="flex flex-col gap-8">
            <section className="flex flex-col gap-3">
                <SectionHead
                    title="Reportes disponibles"
                    description={
                        canGenerate
                            ? 'Elige el formato y pulsa Generar. En unos segundos el archivo aparece abajo, listo para descargar.'
                            : 'Resúmenes de tu operación listos para descargar. Pide a un administrador de tu cuenta que te dé permiso para generarlos.'
                    }
                />
                {reports.length === 0 ? (
                    <div className="rounded-lg border border-border bg-surface-1">
                        <EmptyState
                            icon={FileBarChart2}
                            title="Todavía no hay reportes configurados"
                            description="Aquí verás reportes descargables con la actividad de tu operación: incidentes, flota y consumo. El equipo de SAM los configura contigo; escríbenos y los activamos para tu cuenta."
                        />
                    </div>
                ) : (
                    <div className="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                        {reports.map((report) => (
                            <ReportCard
                                key={report.id}
                                report={report}
                                formats={available}
                                canGenerate={canGenerate}
                                busy={
                                    generating?.startsWith(`${report.id}:`) ??
                                    false
                                }
                                onGenerate={(format) =>
                                    generate(report, format)
                                }
                            />
                        ))}
                    </div>
                )}
            </section>

            <section className="flex flex-col gap-3">
                <SectionHead
                    title="Historial"
                    description="Los últimos 20 reportes generados, a mano o de forma automática."
                />
                {executions.length === 0 ? (
                    <div className="rounded-lg border border-border bg-surface-1">
                        <EmptyState
                            icon={FileClock}
                            title="Aún no has generado ningún reporte"
                            description={
                                reports.length > 0 && canGenerate
                                    ? 'Elige uno de arriba, su formato y pulsa Generar. Aparecerá aquí para descargarlo, y quedará guardado unos días.'
                                    : 'Cuando se genere un reporte aparecerá aquí para descargarlo.'
                            }
                        />
                    </div>
                ) : (
                    <ul className="flex flex-col gap-px overflow-hidden rounded-lg border border-border bg-border">
                        {executions.map((execution) => {
                            const report =
                                execution.reportId !== null
                                    ? reportsById.get(execution.reportId)
                                    : undefined;

                            return (
                                <ExecutionItem
                                    key={execution.id}
                                    execution={execution}
                                    teamSlug={teamSlug}
                                    canRetry={
                                        canGenerate && report !== undefined
                                    }
                                    busy={
                                        report !== undefined &&
                                        generating ===
                                            `${report.id}:${execution.format}`
                                    }
                                    onRetry={() => {
                                        if (report) {
                                            void generate(
                                                report,
                                                execution.format,
                                            );
                                        }
                                    }}
                                />
                            );
                        })}
                    </ul>
                )}
            </section>
        </div>
    );
}
