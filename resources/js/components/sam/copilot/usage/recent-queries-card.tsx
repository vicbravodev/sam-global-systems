import { ThumbsDown, ThumbsUp } from 'lucide-react';
import {
    formatTokens,
    formatUsd,
    timeAgo,
} from '@/components/sam/copilot/copilot-format';
import { COPILOT_CHANNEL_LABELS } from '@/components/sam/copilot/copy';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { CopilotUsageReport } from '@/types/copilot';

export function RecentQueriesCard({
    rows,
}: {
    rows: CopilotUsageReport['recent'];
}) {
    return (
        <Card className="gap-0 py-0">
            <CardHeader className="border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0">
                    Consultas recientes
                </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
                {rows.length === 0 ? (
                    <p className="px-4 py-8 text-center text-sm text-fg-3">
                        Sin consultas registradas.
                    </p>
                ) : (
                    <ul className="divide-y divide-border">
                        {rows.map((row) => (
                            <li
                                key={row.id}
                                className="grid grid-cols-1 gap-1 px-4 py-2.5 md:grid-cols-[minmax(0,1fr)_auto] md:items-center md:gap-4"
                            >
                                <div className="min-w-0">
                                    <div className="truncate text-sm text-fg-1">
                                        {row.question || '—'}
                                    </div>
                                    <div className="mt-0.5 flex flex-wrap gap-x-2 text-2xs text-fg-3">
                                        <span>{row.user ?? 'Usuario'}</span>
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
                                        <span>· {timeAgo(row.createdAt)}</span>
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
                                            row.inputTokens + row.outputTokens,
                                        )}{' '}
                                        tok
                                    </span>
                                    <span>{formatUsd(row.cost)}</span>
                                    <span>
                                        {(row.latencyMs / 1000).toFixed(1)} s
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
    );
}
