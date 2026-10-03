import {
    formatTokens,
    formatUsd,
} from '@/components/sam/copilot/copilot-format';
import { formatNumber } from '@/lib/format';
import type { CopilotUsageReport } from '@/types/copilot';

/** Period totals: queries, users, tokens, cost, latency, satisfaction. */
export function UsageMetrics({
    totals: t,
}: {
    totals: CopilotUsageReport['totals'];
}) {
    return (
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
                value={t.satisfaction !== null ? `${t.satisfaction}%` : '—'}
                hint={`${t.rated} respuesta(s) calificadas`}
            />
        </div>
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
