import { Gauge } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { formatCurrency, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { UsageCounterRow } from '@/types/dashboard';
import { percentLabel } from './lib';

export function UsagePanel({ usage }: { usage: UsageCounterRow[] }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <Gauge size={15} /> Uso del plan
                </CardTitle>
                <span className="sam-meta">
                    {usage.length}{' '}
                    {usage.length === 1 ? 'medidor' : 'medidores'}
                </span>
            </CardHeader>
            {usage.length === 0 ? (
                <CardContent className="p-3">
                    {/* B3: el panel queda presente (en vez de return null) para
                        que la columna izquierda llene el alto y no haya hueco. */}
                    <EmptyState
                        icon={Gauge}
                        title="Sin consumo medido en este periodo todavía"
                        description="Cuando tu operación genere actividad facturable (eventos, media, llamadas de verificación), el consumo del plan aparecerá aquí."
                    />
                </CardContent>
            ) : (
                <CardContent className="grid gap-3 p-3 sm:grid-cols-2 xl:grid-cols-4">
                    {usage.map((counter) => {
                        // Medidores de dinero (mensajería cost-plus): se
                        // muestra el importe a cobrar, nunca micro-USD crudos.
                        if (counter.amount !== null) {
                            return (
                                <div
                                    key={counter.meterCode}
                                    className="rounded-md border border-border bg-surface-2 p-3"
                                >
                                    <div className="truncate text-sm font-semibold">
                                        {counter.meterName}
                                    </div>
                                    <div className="mt-1 font-mono text-xl tabular-nums">
                                        {formatCurrency(counter.amount, 'USD')}
                                    </div>
                                    <div className="mt-2 flex items-center justify-between font-mono text-3xs text-fg-3">
                                        <span>a cobrar en el periodo</span>
                                        {counter.periodEnd ? (
                                            <span>
                                                renueva {counter.periodEnd}
                                            </span>
                                        ) : null}
                                    </div>
                                </div>
                            );
                        }

                        const hasOverage = counter.overage > 0;
                        const fillPct = Math.min(counter.percentUsed ?? 0, 100);

                        return (
                            <div
                                key={counter.meterCode}
                                className="rounded-md border border-border bg-surface-2 p-3"
                            >
                                <div className="truncate text-sm font-semibold">
                                    {counter.meterName}
                                </div>
                                <div className="mt-1 font-mono text-xl tabular-nums">
                                    {formatNumber(counter.consumed)}
                                    <span className="text-sm text-fg-3">
                                        {' '}
                                        / {formatNumber(counter.included)}{' '}
                                        {counter.unit}
                                    </span>
                                </div>
                                <div className="mt-2 h-1 overflow-hidden rounded-full bg-surface-3">
                                    <div
                                        className={cn(
                                            'h-full rounded-full',
                                            hasOverage
                                                ? 'bg-severity-high'
                                                : 'bg-primary',
                                        )}
                                        style={{ width: `${fillPct}%` }}
                                    />
                                </div>
                                <div className="mt-2 flex items-center justify-between font-mono text-3xs text-fg-3">
                                    <span>
                                        {hasOverage
                                            ? `+${formatNumber(counter.overage)} excedente`
                                            : percentLabel(counter.percentUsed)}
                                    </span>
                                    {counter.periodEnd ? (
                                        <span>renueva {counter.periodEnd}</span>
                                    ) : null}
                                </div>
                            </div>
                        );
                    })}
                </CardContent>
            )}
        </Card>
    );
}
