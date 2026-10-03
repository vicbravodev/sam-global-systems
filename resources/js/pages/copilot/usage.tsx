import type { SharedPageProps } from '@inertiajs/core';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, ThumbsDown, ThumbsUp } from 'lucide-react';
import { Bars } from '@/components/sam/charts';
import {
    formatTokens,
    formatUsd,
    timeAgo,
} from '@/components/sam/copilot/copilot-format';
import { COPILOT_CHANNEL_LABELS } from '@/components/sam/copilot/copy';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import copilotRoutes from '@/routes/copilot';
import type { CopilotUsageReport } from '@/types/copilot';

const RANGES = [7, 30, 90] as const;

export default function CopilotUsage({ usage }: { usage: CopilotUsageReport }) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? '';
    const t = usage.totals;

    const setRange = (days: number) =>
        router.get(
            copilotRoutes.usage.url(teamSlug),
            { days },
            { preserveScroll: true, preserveState: true },
        );

    const labels = usage.series.map((point, index) =>
        index % Math.ceil(usage.series.length / 10) === 0
            ? new Date(`${point.date}T00:00:00`).toLocaleDateString('es', {
                  day: 'numeric',
                  month: 'short',
              })
            : '',
    );

    const totalChannel = Object.values(usage.byChannel).reduce(
        (a, b) => a + b,
        0,
    );
    const maxIntent = Math.max(1, ...usage.byIntent.map((i) => i.queries));

    return (
        <>
            <Head title="Uso de SAM Copilot" />
            <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-5">
                <PageHeader
                    title="Uso de SAM Copilot"
                    description="Consultas, tokens consumidos, costo estimado y quién usa el asistente en tu empresa."
                    actions={
                        <>
                            <div className="flex gap-0.5 rounded-md border border-border bg-surface-2 p-0.5">
                                {RANGES.map((days) => (
                                    <button
                                        key={days}
                                        type="button"
                                        onClick={() => setRange(days)}
                                        className={cn(
                                            'cursor-pointer rounded-sm px-2.5 py-1 text-xs font-medium',
                                            usage.range.days === days
                                                ? 'bg-surface-1 text-fg-1 shadow-xs'
                                                : 'text-fg-3 hover:text-fg-1',
                                        )}
                                    >
                                        {days} días
                                    </button>
                                ))}
                            </div>
                            <Link
                                href={copilotRoutes.index(teamSlug)}
                                className="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-1.5 text-sm text-fg-2 hover:bg-surface-2"
                            >
                                <ArrowLeft className="size-4" /> Volver al chat
                            </Link>
                        </>
                    }
                />

                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <Metric label="Consultas" value={formatNumber(t.queries)} />
                    <Metric
                        label="Usuarios activos"
                        value={formatNumber(t.activeUsers)}
                    />
                    <Metric
                        label="Tokens"
                        value={formatTokens(t.inputTokens + t.outputTokens)}
                        hint={`${formatTokens(t.inputTokens)} entrada · ${formatTokens(t.outputTokens)} salida`}
                    />
                    <Metric
                        label="Costo estimado"
                        value={formatUsd(t.cost)}
                        hint={`${formatTokens(t.avgTokensPerQuery)} tokens por consulta`}
                    />
                    <Metric
                        label="Latencia media"
                        value={`${(t.avgLatencyMs / 1000).toFixed(1)} s`}
                    />
                    <Metric
                        label="Satisfacción"
                        value={
                            t.satisfaction !== null ? `${t.satisfaction}%` : '—'
                        }
                        hint={`${t.rated} respuesta(s) calificadas`}
                    />
                </div>

                <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <Card className="gap-0 py-0">
                        <CardHeader className="border-b border-border px-4 py-3">
                            <CardTitle className="sam-h3 m-0">
                                Consultas por día
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="px-4 py-3">
                            {t.queries === 0 ? (
                                <p className="py-10 text-center text-sm text-fg-3">
                                    Sin consultas en el periodo.
                                </p>
                            ) : (
                                <Bars
                                    data={usage.series.map((p) => p.queries)}
                                    labels={labels}
                                    height={200}
                                    width={720}
                                    color="var(--ai-accent)"
                                    valueFmt={(v) => `${v} consultas`}
                                    aria-label="Consultas por día"
                                />
                            )}
                        </CardContent>
                    </Card>

                    <Card className="gap-0 py-0">
                        <CardHeader className="border-b border-border px-4 py-3">
                            <CardTitle className="sam-h3 m-0">
                                Cuota del mes
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4 px-4 py-4">
                            <div>
                                <div className="flex items-baseline justify-between">
                                    <span className="font-mono text-2xl font-semibold text-fg-1 tabular-nums">
                                        {formatNumber(usage.quota.used)}
                                    </span>
                                    <span className="text-xs text-fg-3">
                                        {usage.quota.included !== null
                                            ? `de ${formatNumber(usage.quota.included)} incluidas`
                                            : 'sin tope en el plan'}
                                    </span>
                                </div>
                                {usage.quota.included !== null && (
                                    <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-surface-3">
                                        <div
                                            className={cn(
                                                'h-full rounded-full',
                                                (usage.quota.percent ?? 0) >=
                                                    100
                                                    ? 'bg-severity-critical'
                                                    : (usage.quota.percent ??
                                                            0) >= 80
                                                      ? 'bg-severity-high'
                                                      : 'bg-ai-accent',
                                            )}
                                            style={{
                                                width: `${Math.min(100, usage.quota.percent ?? 0)}%`,
                                            }}
                                        />
                                    </div>
                                )}
                                {usage.quota.overage > 0 && (
                                    <p className="mt-2 text-xs text-severity-critical">
                                        {formatNumber(usage.quota.overage)}{' '}
                                        consultas de excedente se facturarán
                                        este mes.
                                    </p>
                                )}
                            </div>
                            <div>
                                <div className="sam-caps mb-1.5">Canal</div>
                                {Object.entries(usage.byChannel).length ===
                                    0 && <p className="text-xs text-fg-3">—</p>}
                                {Object.entries(usage.byChannel).map(
                                    ([channel, count]) => (
                                        <ShareRow
                                            key={channel}
                                            label={
                                                COPILOT_CHANNEL_LABELS[
                                                    channel
                                                ] ?? channel
                                            }
                                            value={count}
                                            max={totalChannel}
                                        />
                                    ),
                                )}
                            </div>
                            <div>
                                <div className="sam-caps mb-1.5">
                                    Qué preguntan
                                </div>
                                {usage.byIntent.length === 0 && (
                                    <p className="text-xs text-fg-3">—</p>
                                )}
                                {usage.byIntent.map((row) => (
                                    <ShareRow
                                        key={row.intent ?? 'none'}
                                        label={row.label}
                                        value={row.queries}
                                        max={maxIntent}
                                    />
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card className="gap-0 py-0">
                    <CardHeader className="border-b border-border px-4 py-3">
                        <CardTitle className="sam-h3 m-0">
                            Uso por usuario
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {usage.byUser.length === 0 ? (
                            <EmptyState
                                title="Nadie ha usado SAM Copilot todavía"
                                description="Cuando tu equipo haga consultas, aquí verás quién lo usa y cuánto consume."
                            />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="sam-caps border-b border-border bg-surface-2 text-left">
                                            <th className="px-4 py-2">
                                                Usuario
                                            </th>
                                            <th className="px-3 py-2 text-right">
                                                Consultas
                                            </th>
                                            <th className="px-3 py-2 text-right">
                                                Tokens
                                            </th>
                                            <th className="px-3 py-2 text-right">
                                                Costo
                                            </th>
                                            <th className="px-4 py-2 text-right">
                                                Último uso
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {usage.byUser.map((row) => (
                                            <tr
                                                key={row.userId}
                                                className="hover:bg-surface-2"
                                            >
                                                <td className="px-4 py-2">
                                                    <div className="font-medium text-fg-1">
                                                        {row.name}
                                                    </div>
                                                    {row.email && (
                                                        <div className="text-2xs text-fg-3">
                                                            {row.email}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 text-right font-mono tabular-nums">
                                                    {formatNumber(row.queries)}
                                                </td>
                                                <td className="px-3 py-2 text-right font-mono tabular-nums">
                                                    {formatTokens(row.tokens)}
                                                </td>
                                                <td className="px-3 py-2 text-right font-mono tabular-nums">
                                                    {formatUsd(row.cost)}
                                                </td>
                                                <td className="px-4 py-2 text-right text-xs text-fg-3">
                                                    {timeAgo(row.lastUsedAt)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card className="gap-0 py-0">
                    <CardHeader className="border-b border-border px-4 py-3">
                        <CardTitle className="sam-h3 m-0">
                            Consultas recientes
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {usage.recent.length === 0 ? (
                            <p className="px-4 py-8 text-center text-sm text-fg-3">
                                Sin consultas registradas.
                            </p>
                        ) : (
                            <ul className="divide-y divide-border">
                                {usage.recent.map((row) => (
                                    <li
                                        key={row.id}
                                        className="grid grid-cols-1 gap-1 px-4 py-2.5 md:grid-cols-[minmax(0,1fr)_auto] md:items-center md:gap-4"
                                    >
                                        <div className="min-w-0">
                                            <div className="truncate text-sm text-fg-1">
                                                {row.question || '—'}
                                            </div>
                                            <div className="mt-0.5 flex flex-wrap gap-x-2 text-2xs text-fg-3">
                                                <span>
                                                    {row.user ?? 'Usuario'}
                                                </span>
                                                {row.intent && (
                                                    <span>· {row.intent}</span>
                                                )}
                                                {row.channel && (
                                                    <span>
                                                        ·{' '}
                                                        {COPILOT_CHANNEL_LABELS[
                                                            row.channel
                                                        ] ?? row.channel}
                                                    </span>
                                                )}
                                                <span>
                                                    · {timeAgo(row.createdAt)}
                                                </span>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3 font-mono text-2xs text-fg-2 tabular-nums">
                                            <span
                                                title={
                                                    row.model ??
                                                    'Sin modelo de lenguaje'
                                                }
                                            >
                                                {formatTokens(
                                                    row.inputTokens +
                                                        row.outputTokens,
                                                )}{' '}
                                                tok
                                            </span>
                                            <span>{formatUsd(row.cost)}</span>
                                            <span>
                                                {(row.latencyMs / 1000).toFixed(
                                                    1,
                                                )}{' '}
                                                s
                                            </span>
                                            {row.feedback === 1 && (
                                                <ThumbsUp className="size-3 text-health-ok" />
                                            )}
                                            {row.feedback === -1 && (
                                                <ThumbsDown className="size-3 text-severity-critical" />
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

function Metric({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint?: string;
}) {
    return (
        <div className="rounded-lg border border-border bg-surface-1 px-4 py-3">
            <div className="sam-caps">{label}</div>
            <div className="mt-1.5 font-mono text-xl font-semibold text-fg-1 tabular-nums">
                {value}
            </div>
            {hint && <div className="mt-1 text-2xs text-fg-3">{hint}</div>}
        </div>
    );
}

function ShareRow({
    label,
    value,
    max,
}: {
    label: string;
    value: number;
    max: number;
}) {
    return (
        <div className="flex items-center gap-2 py-1 text-xs">
            <span className="w-36 shrink-0 truncate text-fg-2">{label}</span>
            <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-3">
                <span
                    className="block h-full rounded-full bg-ai-accent"
                    style={{ width: `${(value / Math.max(1, max)) * 100}%` }}
                />
            </span>
            <span className="w-10 text-right font-mono text-fg-1 tabular-nums">
                {value}
            </span>
        </div>
    );
}

CopilotUsage.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'SAM Copilot',
            href: props.currentTeam
                ? copilotRoutes.index.url(props.currentTeam.slug)
                : '#',
        },
        {
            title: 'Uso',
            href: props.currentTeam
                ? copilotRoutes.usage.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
