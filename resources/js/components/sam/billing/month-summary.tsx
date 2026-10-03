import { Info } from 'lucide-react';
import type { ReactNode } from 'react';
import { Meter } from '@/components/sam/meter';
import {
    formatDate,
    formatMonthYear,
    formatNumber,
    formatShortDate,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import { money } from './panel';
import type { DailyClose, PeriodEstimate } from './types';

function monthName(iso: string): string {
    return formatMonthYear(iso);
}

function shortDay(iso: string): string {
    return formatShortDate(iso);
}

interface Line {
    key: string;
    concept: string;
    detail: ReactNode;
    toDate: number;
    projected: number;
    tone?: 'warn';
}

/**
 * Las líneas que tendrá la factura del mes, en el mismo orden que la cierra
 * GenerateInvoiceSnapshotJob. "A hoy" + "Al cierre" por línea, y los
 * totales son exactamente la suma de las líneas.
 */
function buildLines(e: PeriodEstimate): Line[] {
    const c = e.currency;
    const lines: Line[] = [];
    const includedDaysToDate = e.assetDays - e.assetDaysExtra;

    lines.push({
        key: 'assets',
        concept: 'Vigilancia de unidades',
        detail: (
            <>
                {formatNumber(includedDaysToDate)} tracto-días a{' '}
                {money(e.dailyRate, c)} por día
                {e.minBillableAssets > 0 &&
                    ` · mínimo ${formatNumber(e.minBillableAssets)} unidades al mes`}
            </>
        ),
        toDate: e.assetsToDate - e.assetsExtraToDate,
        projected: e.assetsProjected - e.assetsExtraProjected,
    });

    if (
        e.cap !== null &&
        (e.assetsExtraToDate > 0 || e.assetsExtraProjected > 0)
    ) {
        lines.push({
            key: 'extra',
            concept: 'Unidades por encima del tope',
            detail: (
                <>
                    Lo que pasa de tus {formatNumber(e.cap)} unidades
                    contratadas, al mismo precio por día ·{' '}
                    {formatNumber(e.assetDaysExtra)} tracto-días a hoy,{' '}
                    {formatNumber(e.projectedAssetDaysExtra)} al cierre
                </>
            ),
            toDate: e.assetsExtraToDate,
            projected: e.assetsExtraProjected,
            tone: 'warn',
        });
    }

    lines.push({
        key: 'ai',
        concept: 'Evaluaciones de IA extra',
        detail:
            e.aiOverage > 0 ? (
                <>
                    {formatNumber(e.aiOverage)} por encima de las{' '}
                    {formatNumber(e.aiIncluded)} incluidas, a{' '}
                    {money(e.aiOverageUnitPrice, c)} cada una
                </>
            ) : (
                <>
                    Llevas {formatNumber(e.aiCalls)} de{' '}
                    {formatNumber(e.aiIncluded)} incluidas este mes (
                    {formatNumber(e.aiFairUsePerAsset)} por unidad): sin costo
                    extra
                </>
            ),
        toDate: e.aiToDate,
        projected: e.aiProjected,
        tone: e.aiOverage > 0 ? 'warn' : undefined,
    });

    if (e.unmonitoredEmergencyDays > 0) {
        lines.push({
            key: 'unmonitored-emergencies',
            concept: 'Emergencias en unidades no vigiladas',
            detail: (
                <>
                    {formatNumber(e.unmonitoredEmergencyDays)}{' '}
                    {e.unmonitoredEmergencyDays === 1
                        ? 'unidad-día atendida'
                        : 'unidad-días atendidas'}{' '}
                    sin estar vigiladas: tracto-día +
                    {formatNumber(e.unmonitoredEmergencySurchargePercent)}%
                </>
            ),
            toDate: e.unmonitoredEmergencyToDate,
            projected: e.unmonitoredEmergencyToDate,
            tone: 'warn',
        });
    }

    lines.push({
        key: 'messaging',
        concept: 'Mensajería y llamadas',
        detail: 'Avisos por SMS, WhatsApp y llamada: costo real más margen. Al cierre se suma lo que se envíe el resto del mes.',
        toDate: e.messagingToDate,
        projected: e.messagingToDate,
    });

    return lines;
}

function Amount({
    value,
    currency,
    className,
}: {
    value: number;
    currency: string;
    className?: string;
}) {
    return (
        <span className={cn('whitespace-nowrap tabular-nums', className)}>
            {money(value, currency)}
        </span>
    );
}

export function MonthSummary({ estimate }: { estimate: PeriodEstimate }) {
    const e = estimate;
    const c = e.currency;
    const lines = buildLines(e);
    const daysLeft = Math.max(0, e.daysInPeriod - e.daysElapsed);
    const missingDays = Math.max(0, e.daysElapsed - e.daysRecorded);

    return (
        <section className="flex min-w-0 flex-col rounded-lg border border-border bg-surface-1">
            {/* Héroe: cuánto llevas y a cuánto cerrarías. */}
            <div className="flex flex-col gap-4 px-4 pt-4 pb-3 sm:px-5">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 className="sam-h4">Tu mes hasta hoy</h2>
                    <span className="text-xs text-fg-3">
                        {monthName(e.periodStart)}
                    </span>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="flex flex-col gap-0.5">
                        <span className="sam-caps">Acumulado a hoy</span>
                        <span className="text-3xl leading-tight font-semibold tracking-tight text-fg-1 tabular-nums">
                            {money(e.totalToDate, c)}
                        </span>
                        <span className="text-xs text-fg-3">
                            Del {shortDay(e.periodStart)} a hoy
                        </span>
                    </div>
                    <div className="flex flex-col gap-0.5">
                        <span className="sam-caps">Proyección al cierre</span>
                        <span className="text-2xl leading-tight font-semibold tracking-tight text-fg-2 tabular-nums">
                            {money(e.totalProjected, c)}
                        </span>
                        <span className="text-xs text-fg-3">
                            Si mantienes {formatNumber(e.monitoredNow)}{' '}
                            {e.monitoredNow === 1
                                ? 'unidad vigilada'
                                : 'unidades vigiladas'}{' '}
                            hasta el {shortDay(e.periodEnd)}
                        </span>
                    </div>
                </div>
                <div className="flex flex-col gap-1.5">
                    <Meter
                        value={e.daysElapsed}
                        max={e.daysInPeriod}
                        label="Avance del mes"
                        role="progressbar"
                    />
                    <div className="flex flex-wrap justify-between gap-x-4 text-2xs text-fg-3">
                        <span>
                            Día {e.daysElapsed} de {e.daysInPeriod}
                            {daysLeft > 0 &&
                                ` · ${daysLeft === 1 ? 'falta 1 día' : `faltan ${daysLeft} días`}`}
                        </span>
                        <span>Cierre del mes: {formatDate(e.periodEnd)}</span>
                    </div>
                </div>
                {missingDays > 0 && (
                    <p className="flex items-start gap-2 rounded-md border border-severity-info/30 bg-severity-info/10 px-3 py-2 text-xs text-fg-2">
                        <Info
                            className="mt-0.5 size-3.5 shrink-0 text-severity-info"
                            aria-hidden="true"
                        />
                        {e.daysRecorded === 0 ? (
                            <span>
                                Aún no hay noches registradas este mes. Cada
                                noche contamos tus unidades vigiladas y ese
                                conteo es lo que se cobra; los días sin conteo
                                no se cobran, por eso la vigilancia va en cero.
                            </span>
                        ) : (
                            <span>
                                {missingDays === 1
                                    ? 'Hay 1 día'
                                    : `Hay ${missingDays} días`}{' '}
                                de este mes sin conteo de unidades vigiladas;
                                esos días no se cobran.
                            </span>
                        )}
                    </p>
                )}
            </div>

            {/* Desglose: las líneas de la factura. */}
            <div className="border-t border-border">
                <div className="sam-caps hidden items-center gap-4 px-5 py-2 sm:flex">
                    <span className="flex-1">Cómo se forma tu factura</span>
                    <span className="w-32 text-right">A hoy</span>
                    <span className="w-32 text-right">Al cierre</span>
                </div>
                <div className="sam-caps px-4 py-2 sm:hidden">
                    Cómo se forma tu factura
                </div>
                <ul className="divide-y divide-border border-t border-border">
                    {lines.map((line) => (
                        <li
                            key={line.key}
                            className="flex flex-col gap-1.5 px-4 py-2.5 sm:flex-row sm:items-center sm:gap-4 sm:px-5"
                        >
                            <div className="min-w-0 flex-1">
                                <div
                                    className={cn(
                                        'text-sm font-medium',
                                        line.tone === 'warn'
                                            ? 'text-severity-medium'
                                            : 'text-fg-1',
                                    )}
                                >
                                    {line.concept}
                                </div>
                                <div className="text-xs text-fg-3">
                                    {line.detail}
                                </div>
                            </div>
                            <div className="flex gap-4 text-sm sm:contents">
                                <div className="flex flex-col sm:w-32 sm:text-right">
                                    <span className="text-3xs text-fg-3 uppercase sm:hidden">
                                        A hoy
                                    </span>
                                    <Amount
                                        value={line.toDate}
                                        currency={c}
                                        className="text-fg-1"
                                    />
                                </div>
                                <div className="flex flex-col sm:w-32 sm:text-right">
                                    <span className="text-3xs text-fg-3 uppercase sm:hidden">
                                        Al cierre
                                    </span>
                                    <Amount
                                        value={line.projected}
                                        currency={c}
                                        className="text-fg-2"
                                    />
                                </div>
                            </div>
                        </li>
                    ))}
                    <li className="flex flex-col gap-1.5 bg-surface-2 px-4 py-2.5 sm:flex-row sm:items-center sm:gap-4 sm:px-5">
                        <div className="flex-1 text-sm font-semibold text-fg-1">
                            Total
                        </div>
                        <div className="flex gap-4 text-sm font-semibold sm:contents">
                            <div className="flex flex-col sm:w-32 sm:text-right">
                                <span className="text-3xs font-normal text-fg-3 uppercase sm:hidden">
                                    A hoy
                                </span>
                                <Amount
                                    value={e.totalToDate}
                                    currency={c}
                                    className="text-fg-1"
                                />
                            </div>
                            <div className="flex flex-col sm:w-32 sm:text-right">
                                <span className="text-3xs font-normal text-fg-3 uppercase sm:hidden">
                                    Al cierre
                                </span>
                                <Amount
                                    value={e.totalProjected}
                                    currency={c}
                                    className="text-fg-1"
                                />
                            </div>
                        </div>
                    </li>
                </ul>
                <p className="border-t border-border px-4 py-2 text-2xs text-fg-3 sm:px-5">
                    Un tracto-día es una unidad vigilada durante un día (basta
                    con que esté encendida en algún momento del día). Las cifras
                    al cierre son una estimación: la factura final usa los
                    cierres diarios reales.
                </p>
            </div>
            {e.dailyCloses.length > 0 && (
                <DailyCloses closes={e.dailyCloses} currency={c} />
            )}
        </section>
    );
}

/**
 * Cierre por día del mes en curso: qué se registró cada día y cuánto suma,
 * para que el cliente vea su uso en claro antes de la factura.
 */
function DailyCloses({
    closes,
    currency,
}: {
    closes: DailyClose[];
    currency: string;
}) {
    return (
        <details className="border-t border-border">
            <summary className="cursor-pointer px-4 py-2.5 text-xs font-medium text-fg-2 sm:px-5">
                Cierres diarios del mes ({closes.length})
            </summary>
            <div className="overflow-x-auto">
                <table className="w-full text-xs">
                    <thead className="text-fg-3">
                        <tr>
                            <th className="px-4 py-2 text-left font-medium sm:px-5">
                                Día
                            </th>
                            <th className="px-4 py-2 text-right font-medium">
                                Tracto-días
                            </th>
                            <th className="px-4 py-2 text-right font-medium">
                                Emergencias no vigiladas
                            </th>
                            <th className="px-4 py-2 text-right font-medium sm:px-5">
                                Importe
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {closes.map((close) => (
                            <tr
                                key={close.date}
                                className="border-t border-border"
                            >
                                <td className="px-4 py-1.5 text-fg-1 sm:px-5">
                                    {shortDay(close.date)}
                                </td>
                                <td className="px-4 py-1.5 text-right tabular-nums">
                                    {formatNumber(close.assetDays)}
                                </td>
                                <td className="px-4 py-1.5 text-right tabular-nums">
                                    {formatNumber(close.emergencyDays)}
                                </td>
                                <td className="px-4 py-1.5 text-right sm:px-5">
                                    <Amount
                                        value={close.amount}
                                        currency={currency}
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </details>
    );
}
