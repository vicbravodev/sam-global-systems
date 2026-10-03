import { COPILOT_CHANNEL_LABELS } from '@/components/sam/copilot/copy';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { CopilotUsageReport } from '@/types/copilot';

/** Monthly quota, plus the channel and intent breakdowns. */
export function QuotaCard({ usage }: { usage: CopilotUsageReport }) {
    const totalChannel = Object.values(usage.byChannel).reduce(
        (a, b) => a + b,
        0,
    );
    const maxIntent = Math.max(1, ...usage.byIntent.map((i) => i.queries));

    return (
        <Card className="gap-0 py-0">
            <CardHeader className="border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0">Cuota del mes</CardTitle>
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
                                    (usage.quota.percent ?? 0) >= 100
                                        ? 'bg-severity-critical'
                                        : (usage.quota.percent ?? 0) >= 80
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
                            {formatNumber(usage.quota.overage)} consultas de
                            excedente se facturarán este mes.
                        </p>
                    )}
                </div>
                <div>
                    <div className="sam-caps mb-1.5">Canal</div>
                    {Object.entries(usage.byChannel).length === 0 && (
                        <p className="text-xs text-fg-3">—</p>
                    )}
                    {Object.entries(usage.byChannel).map(([channel, count]) => (
                        <ShareRow
                            key={channel}
                            label={COPILOT_CHANNEL_LABELS[channel] ?? channel}
                            value={count}
                            max={totalChannel}
                        />
                    ))}
                </div>
                <div>
                    <div className="sam-caps mb-1.5">Qué preguntan</div>
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
