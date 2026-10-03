import {
    formatTokens,
    formatUsd,
    timeAgo,
} from '@/components/sam/copilot/copilot-format';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { formatNumber } from '@/lib/format';
import type { CopilotUsageReport } from '@/types/copilot';

export function UsageByUserCard({
    rows,
}: {
    rows: CopilotUsageReport['byUser'];
}) {
    return (
        <Card className="gap-0 py-0">
            <CardHeader className="border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0">Uso por usuario</CardTitle>
            </CardHeader>
            <CardContent className="p-0">
                {rows.length === 0 ? (
                    <EmptyState
                        title="Nadie ha usado SAM Copilot todavía"
                        description="Cuando tu equipo haga consultas, aquí verás quién lo usa y cuánto consume."
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="sam-caps border-b border-border bg-surface-2 text-left">
                                    <th className="px-4 py-2">Usuario</th>
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
                                {rows.map((row) => (
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
    );
}
