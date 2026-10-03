import { Gauge } from 'lucide-react';
import type { UsageRow } from '@/components/sam/admin/tenant/types';
import { Panel } from '@/components/sam/panel';
import { EmptyState } from '@/components/ui/empty-state';
import { formatCurrency, formatDate } from '@/lib/format';
import { meterLabel } from '@/lib/labels';
import { cn } from '@/lib/utils';

export function UsageTab({ usage }: { usage: UsageRow[] }) {
    if (usage.length === 0) {
        return (
            <EmptyState
                icon={Gauge}
                title="Sin consumo registrado"
                description="Aparece en cuanto el cliente vigile unidades y lleguen eventos."
            />
        );
    }

    return (
        <Panel
            size="lg"
            bodyClassName="p-4"
            title="Consumo por periodo"
            description="Últimos 20 contadores, del periodo más reciente al más antiguo."
        >
            <div className="overflow-x-auto">
                <table className="w-full min-w-[560px] text-sm">
                    <thead>
                        <tr className="sam-caps border-b border-border text-left">
                            <th className="py-2 pr-3">Periodo</th>
                            <th className="py-2 pr-3">Medidor</th>
                            <th className="py-2 pr-3 text-right">Consumido</th>
                            <th className="py-2 pr-3 text-right">Incluido</th>
                            <th className="py-2 text-right">Excedente</th>
                        </tr>
                    </thead>
                    <tbody>
                        {usage.map((row, i) => (
                            <tr
                                key={`${row.meterCode ?? row.meter}-${row.periodStart}-${i}`}
                                className="border-b border-border last:border-0"
                            >
                                <td className="py-2 pr-3 text-fg-3 tabular-nums">
                                    {formatDate(row.periodStart)}
                                </td>
                                <td className="py-2 pr-3">
                                    {meterLabel(row.meterCode, row.meter)}
                                </td>
                                {row.money ? (
                                    <td
                                        className="py-2 text-right tabular-nums"
                                        colSpan={3}
                                    >
                                        Costo Twilio{' '}
                                        {formatCurrency(
                                            row.money.providerCost,
                                            'usd',
                                        )}{' '}
                                        · cobrado{' '}
                                        {formatCurrency(
                                            row.money.charged,
                                            'usd',
                                        )}
                                    </td>
                                ) : (
                                    <>
                                        <td className="py-2 pr-3 text-right tabular-nums">
                                            {row.consumed}
                                        </td>
                                        <td className="py-2 pr-3 text-right tabular-nums">
                                            {row.included}
                                        </td>
                                        <td
                                            className={cn(
                                                'py-2 text-right tabular-nums',
                                                row.overage > 0 &&
                                                    'font-semibold text-severity-medium',
                                            )}
                                        >
                                            {row.overage}
                                        </td>
                                    </>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Panel>
    );
}
