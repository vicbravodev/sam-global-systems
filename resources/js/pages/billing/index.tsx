import { Head, Link, router, usePage } from '@inertiajs/react';
import { EyeOff, Mail, Users } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { formatCurrency, formatDate, formatNumber } from '@/lib/format';
import {
    featureLabel,
    invoiceStatusLabel,
    meterLabel,
    meterUnitLabel,
    subscriptionStatusLabel,
} from '@/lib/labels';

interface SubscriptionProp {
    planName: string | null;
    planCode: string | null;
    basePrice: number | null;
    currency: string | null;
    billingCycle: string;
    billingCycleLabel: string | null;
    status: string;
    statusLabel: string | null;
    renewsAt: string | null;
}

interface FeatureRow {
    key: string;
    enabled: boolean;
    source: string;
    sourceLabel: string | null;
    limits: Record<string, unknown> | null;
}

interface UsageRow {
    meterCode: string | null;
    /** El plan factura este medidor (tiene tarifa). */
    billed: boolean;
    /** El excedente tiene precio: sólo entonces se marca en rojo. */
    overageCharged: boolean;
    /** Conteo por canal cuyo costo va en la línea de mensajería (Twilio). */
    billedVia: 'messaging' | null;
    meterName: string | null;
    unit: string | null;
    /** Cost-plus meters (Twilio messaging): amount to be charged, in USD. */
    amount: number | null;
    consumed: number;
    included: number;
    overage: number;
    periodStart: string | null;
    periodEnd: string | null;
}

interface InvoiceRow {
    id: number;
    periodStart: string | null;
    periodEnd: string | null;
    subtotal: number;
    overageTotal: number;
    total: number;
    currency: string | null;
    status: string;
    statusLabel: string | null;
    /** Emitida (o borrador de periodo cerrado) y sin pagar. */
    awaitsPayment: boolean;
    paidAt: string | null;
    hasReceipt: boolean;
    paymentNote: string | null;
    breakdown: Record<string, unknown> | null;
}

interface BillingTerms {
    unit_price: number;
    currency: string;
    included_assets: number | null;
    min_billable_assets: number;
    ai_fair_use_per_asset: number;
    ai_overage_unit_price: number;
    messaging_markup_percent: number | null;
    fx_usd_rate: number;
    volume_tiers: { from: number; to: number | null; unit_price: number }[];
    explicit: boolean;
}

interface PeriodEstimate {
    periodStart: string;
    periodEnd: string;
    daysInPeriod: number;
    daysRecorded: number;
    currency: string;
    unitPrice: number;
    dailyRate: number;
    monitoredNow: number;
    cap: number | null;
    overCap: boolean;
    assetDays: number;
    assetDaysExtra: number;
    projectedAssetDays: number;
    assetsToDate: number;
    assetsProjected: number;
    aiCalls: number;
    aiIncluded: number;
    aiOverage: number;
    aiToDate: number;
    aiProjected: number;
    messagingToDate: number;
    unmonitoredEmergencyDays: number;
    unmonitoredEmergencySurchargePercent: number;
    unmonitoredEmergencyToDate: number;
    dailyCloses: DailyClose[];
    totalToDate: number;
    totalProjected: number;
    minBillableAssets: number;
    aiFairUsePerAsset: number;
    aiOverageUnitPrice: number;
}

interface DailyClose {
    date: string;
    assetDays: number;
    emergencyDays: number;
    amount: number;
}

interface FleetCounts {
    monitored: number;
    pending: number;
    excluded: number;
}

interface BillingPageProps {
    supportEmail: string | null;
    subscription: SubscriptionProp | null;
    features: FeatureRow[];
    usage: UsageRow[];
    invoices: InvoiceRow[];
    terms: BillingTerms;
    estimate: PeriodEstimate;
    fleet: FleetCounts;
}

const LINE_LABEL: Record<string, string> = {
    monitored_asset_days: 'Tractos vigilados (por día)',
    ai_calls: 'Evaluaciones de IA',
    messaging_cost_micros: 'Mensajería y llamadas',
};

const SUBSCRIPTION_STATUS_COLOR: Record<string, string> = {
    active: 'text-severity-low',
    trialing: 'text-severity-medium',
    past_due: 'text-severity-high',
    suspended: 'text-severity-critical',
    canceled: 'text-fg-3',
    expired: 'text-fg-3',
};

const INVOICE_STATUS_COLOR: Record<string, string> = {
    paid: 'text-severity-low',
    invoiced: 'text-severity-medium',
    finalized: 'text-severity-medium',
    disputed: 'text-severity-high',
    void: 'text-fg-3',
    draft: 'text-fg-3',
};

function money(value: number, currency: string | null): string {
    return formatCurrency(value, currency);
}

function MetricCell({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1 bg-surface-1 px-4 py-3">
            <span className="text-2xs tracking-caps text-fg-3 uppercase">
                {label}
            </span>
            {children}
        </div>
    );
}

function money2(value: number, currency: string): string {
    return formatCurrency(value, currency.toUpperCase());
}

/**
 * Lo que manda en la factura: unidades vigiladas contra lo contratado y
 * cuánto va acumulado / proyectado este mes. Tope suave: pasarse no bloquea,
 * se cobra como extra por día.
 */
function MonitoringCard({
    terms,
    estimate,
    fleet,
    teamSlug,
}: {
    terms: BillingTerms;
    estimate: PeriodEstimate;
    fleet: FleetCounts;
    teamSlug: string | null;
}) {
    const cap = estimate.cap;
    const currency = estimate.currency;

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between">
                <h2 className="text-2xs font-semibold tracking-caps text-fg-3 uppercase">
                    Vigilancia y estimado del mes
                </h2>
                {teamSlug && (
                    <Link
                        href={`/${teamSlug}/assets`}
                        className="text-xs text-primary hover:underline"
                    >
                        Elegir qué unidades vigilar
                    </Link>
                )}
            </div>
            <div className="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-border bg-border md:grid-cols-4">
                <MetricCell label="Tractos vigilados">
                    <span className="flex items-baseline gap-1.5">
                        <span className="text-xl font-semibold text-fg-1 tabular-nums">
                            {fleet.monitored}
                        </span>
                        <span className="text-xs text-fg-3">
                            {cap === null ? 'sin tope' : `de ${cap}`}
                        </span>
                    </span>
                    <span
                        className={
                            estimate.overCap
                                ? 'text-2xs text-severity-medium'
                                : 'text-2xs text-fg-3'
                        }
                    >
                        {estimate.overCap && cap !== null
                            ? `${fleet.monitored - cap} por encima del tope: se cobran como extra`
                            : fleet.pending > 0
                              ? `${fleet.pending} sin vigilar esperando tu decisión`
                              : 'Sólo lo vigilado se cobra'}
                    </span>
                </MetricCell>
                <MetricCell label="Precio por tracto">
                    <span className="text-xl font-semibold text-fg-1 tabular-nums">
                        {money2(estimate.unitPrice, currency)}
                    </span>
                    <span className="text-2xs text-fg-3">
                        al mes · {money2(estimate.dailyRate, currency)} por día
                        encendido
                        {terms.min_billable_assets > 0 &&
                            ` · mínimo ${terms.min_billable_assets} tractos`}
                    </span>
                </MetricCell>
                <MetricCell label="Acumulado a hoy">
                    <span className="text-xl font-semibold text-fg-1 tabular-nums">
                        {money2(estimate.totalToDate, currency)}
                    </span>
                    <span className="text-2xs text-fg-3">
                        {estimate.assetDays.toLocaleString('es')} tracto-días en{' '}
                        {estimate.daysRecorded} de {estimate.daysInPeriod} días
                    </span>
                </MetricCell>
                <MetricCell label="Proyección al cierre">
                    <span className="text-xl font-semibold text-fg-1 tabular-nums">
                        {money2(estimate.totalProjected, currency)}
                    </span>
                    <span className="text-2xs text-fg-3">
                        si mantienes {estimate.monitoredNow} vigiladas hasta el{' '}
                        {formatDate(estimate.periodEnd)}
                    </span>
                </MetricCell>
            </div>
            <div className="grid gap-px overflow-hidden rounded-lg border border-border bg-border sm:grid-cols-3">
                <MetricCell label="Tractos">
                    <span className="text-sm text-fg-1 tabular-nums">
                        {money2(estimate.assetsToDate, currency)}
                        <span className="text-fg-3">
                            {' '}
                            → {money2(estimate.assetsProjected, currency)}
                        </span>
                    </span>
                    {estimate.assetDaysExtra > 0 && (
                        <span className="text-2xs text-severity-medium">
                            {estimate.assetDaysExtra} tracto-días por encima del
                            tope
                        </span>
                    )}
                </MetricCell>
                <MetricCell label="IA (uso justo)">
                    <span className="text-sm text-fg-1 tabular-nums">
                        {money2(estimate.aiToDate, currency)}
                        <span className="text-fg-3">
                            {' '}
                            → {money2(estimate.aiProjected, currency)}
                        </span>
                    </span>
                    <span className="text-2xs text-fg-3">
                        {estimate.aiCalls.toLocaleString('es')} evaluaciones ·{' '}
                        {estimate.aiIncluded.toLocaleString('es')} incluidas (
                        {estimate.aiFairUsePerAsset} por tracto) ·{' '}
                        {estimate.aiOverage.toLocaleString('es')} extra a{' '}
                        {money2(estimate.aiOverageUnitPrice, currency)}
                    </span>
                </MetricCell>
                <MetricCell label="Mensajería y llamadas">
                    <span className="text-sm text-fg-1 tabular-nums">
                        {money2(estimate.messagingToDate, currency)}
                    </span>
                    <span className="text-2xs text-fg-3">
                        Costo real de SMS, WhatsApp y llamadas con margen
                    </span>
                </MetricCell>
                {estimate.unmonitoredEmergencyDays > 0 && (
                    <MetricCell label="Emergencias en unidades no vigiladas">
                        <span className="text-sm text-fg-1 tabular-nums">
                            {money2(
                                estimate.unmonitoredEmergencyToDate,
                                currency,
                            )}
                        </span>
                        <span className="text-2xs text-severity-medium">
                            {estimate.unmonitoredEmergencyDays} unidad-día
                            {estimate.unmonitoredEmergencyDays === 1
                                ? ''
                                : 's'}{' '}
                            atendida
                            {estimate.unmonitoredEmergencyDays === 1
                                ? ''
                                : 's'}{' '}
                            sin estar vigilada
                            {estimate.unmonitoredEmergencyDays === 1
                                ? ''
                                : 's'}{' '}
                            · tracto-día +
                            {estimate.unmonitoredEmergencySurchargePercent}%
                        </span>
                    </MetricCell>
                )}
            </div>
            {fleet.pending > 0 && teamSlug && (
                <div className="flex flex-col gap-2 rounded-lg border border-severity-medium/40 bg-severity-medium/10 px-4 py-2.5 text-xs text-fg-2 sm:flex-row sm:items-center sm:justify-between">
                    <p>
                        <span className="font-medium text-fg-1">
                            {fleet.pending}{' '}
                            {fleet.pending === 1
                                ? 'unidad nueva sin vigilar.'
                                : 'unidades nuevas sin vigilar.'}
                        </span>{' '}
                        No se cobran hasta que las enciendas.
                    </p>
                    <Button size="sm" variant="outline" asChild>
                        <Link href={`/${teamSlug}/assets?monitoring=pending`}>
                            <EyeOff size={13} />
                            Revisar pendientes
                        </Link>
                    </Button>
                </div>
            )}
            {estimate.dailyCloses.length > 0 && (
                <DailyCloses
                    closes={estimate.dailyCloses}
                    currency={currency}
                />
            )}
        </div>
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
        <details className="rounded-lg border border-border">
            <summary className="cursor-pointer px-4 py-2.5 text-xs font-medium text-fg-2">
                Cierres diarios del mes ({closes.length})
            </summary>
            <div className="overflow-x-auto">
                <table className="w-full text-xs">
                    <thead className="text-fg-3">
                        <tr>
                            <th className="px-4 py-2 text-left font-medium">
                                Día
                            </th>
                            <th className="px-4 py-2 text-right font-medium">
                                Tracto-días
                            </th>
                            <th className="px-4 py-2 text-right font-medium">
                                Emergencias no vigiladas
                            </th>
                            <th className="px-4 py-2 text-right font-medium">
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
                                <td className="px-4 py-1.5 text-fg-1 tabular-nums">
                                    {close.date}
                                </td>
                                <td className="px-4 py-1.5 text-right tabular-nums">
                                    {close.assetDays}
                                </td>
                                <td className="px-4 py-1.5 text-right tabular-nums">
                                    {close.emergencyDays}
                                </td>
                                <td className="px-4 py-1.5 text-right tabular-nums">
                                    {money2(close.amount, currency)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </details>
    );
}

function UsageBar({ row }: { row: UsageRow }) {
    const ratio =
        row.included > 0 ? Math.min(1, row.consumed / row.included) : 0;
    const over = row.overageCharged && row.overage > 0;

    return (
        <div className="h-1.5 w-40 overflow-hidden rounded bg-surface-2">
            <div
                className={
                    over ? 'h-full bg-severity-critical' : 'h-full bg-primary'
                }
                style={{ width: `${Math.max(4, ratio * 100)}%` }}
            />
        </div>
    );
}

function ReceiptUploader({ invoice }: { invoice: InvoiceRow }) {
    const page = usePage();
    const teamSlug =
        (
            page.props as unknown as {
                currentTeam?: { slug?: string | null } | null;
            }
        ).currentTeam?.slug ?? null;
    const inputRef = useRef<HTMLInputElement | null>(null);
    const [uploading, setUploading] = useState(false);

    if (invoice.status === 'paid') {
        return (
            <span className="text-2xs text-severity-low">
                Pagada
                {invoice.paidAt && ` el ${formatDate(invoice.paidAt)}`}
            </span>
        );
    }

    // Sólo una factura pendiente de pago admite comprobante: un borrador del
    // periodo en curso es una vista previa y una anulada no se cobra.
    if (!invoice.awaitsPayment) {
        return (
            <span className="text-2xs text-fg-3">
                {invoice.status === 'void'
                    ? 'Sin cobro'
                    : invoice.status === 'draft'
                      ? 'Periodo en curso'
                      : '—'}
            </span>
        );
    }

    const upload = async (file: File) => {
        if (teamSlug === null) {
            return;
        }

        setUploading(true);

        try {
            const body = new FormData();
            body.append('receipt', file);

            const token =
                document
                    .querySelector('meta[name=csrf-token]')
                    ?.getAttribute('content') ?? '';

            const response = await fetch(
                `/${teamSlug}/billing/invoices/${invoice.id}/receipt`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': token,
                        Accept: 'application/json',
                    },
                    body,
                },
            );

            if (response.ok || response.status === 201) {
                toast.success(
                    'Comprobante enviado. El equipo de SAM lo verificará.',
                );
                router.reload({ only: ['invoices'] });
            } else if (response.status === 403) {
                toast.error('No tienes permisos para subir comprobantes.');
            } else {
                toast.error('No se pudo subir el comprobante.');
            }
        } catch {
            toast.error('Error de red. Vuelve a intentarlo.');
        } finally {
            setUploading(false);
        }
    };

    return (
        <span className="flex items-center gap-2">
            {invoice.hasReceipt && (
                <Badge variant="outline" className="text-severity-medium">
                    comprobante enviado
                </Badge>
            )}
            <button
                type="button"
                disabled={uploading}
                onClick={() => inputRef.current?.click()}
                className="text-2xs text-fg-2 underline hover:text-fg-1"
            >
                {uploading
                    ? 'Subiendo…'
                    : invoice.hasReceipt
                      ? 'Reemplazar comprobante'
                      : 'Subir comprobante'}
            </button>
            <input
                ref={inputRef}
                type="file"
                accept=".pdf,image/*"
                className="hidden"
                onChange={(e) => {
                    const file = e.target.files?.[0];

                    if (file) {
                        void upload(file);
                    }
                }}
            />
        </span>
    );
}

export default function BillingIndex() {
    const page = usePage();
    const {
        supportEmail,
        subscription,
        features,
        usage,
        invoices,
        terms,
        estimate,
        fleet,
    } = page.props as unknown as BillingPageProps;
    const currentTeam = page.props.currentTeam;
    const teamSlug = currentTeam?.slug ?? null;

    const mailtoHref = supportEmail
        ? `mailto:${supportEmail}?subject=${encodeURIComponent(
              `Facturación: ${currentTeam?.name ?? 'mi equipo'}`,
          )}`
        : null;

    return (
        <>
            <Head title="Facturación" />
            <div className="flex flex-col gap-4 p-5">
                <PageHeader
                    title="Facturación"
                    description="Pagas por cada día que una unidad está vigilada. Tú decides cuáles enciendes; lo que pasa del tope contratado se cobra como extra. El pago es por transferencia bancaria."
                />

                <MonitoringCard
                    terms={terms}
                    estimate={estimate}
                    fleet={fleet}
                    teamSlug={teamSlug}
                />

                {/* Plan — tira compacta de métricas (B1): en vez de una
                    tarjeta a todo el ancho con una sola línea de texto. */}
                {subscription === null ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm uppercase">
                                Plan actual
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm text-fg-2">
                            <p className="text-fg-3">
                                Tu equipo todavía no tiene un plan activo. El
                                equipo de SAM lo activa al confirmar tu pago;{' '}
                                {mailtoHref ? (
                                    <a
                                        href={mailtoHref}
                                        className="text-primary hover:underline"
                                    >
                                        escríbenos
                                    </a>
                                ) : (
                                    'escríbenos'
                                )}{' '}
                                si ya realizaste la transferencia.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="flex flex-col gap-2">
                        <div className="flex items-center justify-between">
                            <h2 className="text-2xs font-semibold tracking-caps text-fg-3 uppercase">
                                Plan actual
                            </h2>
                            {currentTeam && (
                                <Link
                                    href={`/settings/teams/${currentTeam.id}`}
                                    className="text-xs text-primary hover:underline"
                                >
                                    Datos del equipo
                                </Link>
                            )}
                        </div>
                        <div className="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-border bg-border md:grid-cols-4">
                            <MetricCell label="Plan">
                                <span className="text-base font-semibold text-fg-1">
                                    {subscription.planName ?? '—'}
                                </span>
                            </MetricCell>
                            <MetricCell label="Estado">
                                <Badge
                                    variant="outline"
                                    className={
                                        SUBSCRIPTION_STATUS_COLOR[
                                            subscription.status
                                        ] ?? 'text-fg-3'
                                    }
                                >
                                    {subscription.statusLabel ??
                                        subscriptionStatusLabel(
                                            subscription.status,
                                        )}
                                </Badge>
                            </MetricCell>
                            <MetricCell label="Tope contratado">
                                <span className="text-base font-semibold text-fg-1">
                                    {/* Mismo tope que "Tractos vigilados": el de
                                        los términos o, sin términos, el del plan. */}
                                    {(estimate.cap ?? terms.included_assets) ===
                                    null
                                        ? 'Sin tope'
                                        : `${formatNumber(estimate.cap ?? terms.included_assets ?? 0)} tractos`}
                                </span>
                            </MetricCell>
                            <MetricCell label="Próxima renovación">
                                <span className="text-base font-semibold text-fg-1">
                                    {formatDate(subscription.renewsAt)}
                                </span>
                            </MetricCell>
                        </div>
                    </div>
                )}

                {/* Usage */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm uppercase">
                            Consumo del periodo
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {usage.length === 0 ? (
                            <p className="text-xs text-fg-3">
                                El consumo aparecerá aquí en cuanto tu operación
                                genere actividad este periodo (eventos, media,
                                llamadas de verificación).
                            </p>
                        ) : (
                            <table className="w-full text-left text-xs">
                                <thead className="text-2xs text-fg-3 uppercase">
                                    <tr>
                                        <th className="py-1.5 pr-4">Medidor</th>
                                        <th className="py-1.5 pr-4">
                                            Consumido
                                        </th>
                                        <th className="py-1.5 pr-4">
                                            Incluido
                                        </th>
                                        <th className="py-1.5 pr-4">Uso</th>
                                        <th className="py-1.5 pr-4">
                                            Excedente
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {usage.map((row) => (
                                        <tr
                                            key={row.meterCode}
                                            className="border-t border-border/50 text-fg-2"
                                        >
                                            <td className="py-2 pr-4">
                                                <span className="text-fg-1">
                                                    {LINE_LABEL[
                                                        row.meterCode ?? ''
                                                    ] ??
                                                        meterLabel(
                                                            row.meterCode,
                                                            row.meterName,
                                                        )}
                                                </span>
                                                {row.amount === null &&
                                                    row.unit && (
                                                        <span className="ml-1 text-2xs text-fg-3">
                                                            (
                                                            {meterUnitLabel(
                                                                row.unit,
                                                            )}
                                                            )
                                                        </span>
                                                    )}
                                            </td>
                                            {row.amount !== null ? (
                                                <>
                                                    <td className="py-2 pr-4 font-medium text-fg-1 tabular-nums">
                                                        {money(
                                                            row.amount,
                                                            'USD',
                                                        )}
                                                    </td>
                                                    <td
                                                        className="py-2 pr-4 text-2xs text-fg-3"
                                                        colSpan={3}
                                                    >
                                                        Según consumo real de
                                                        mensajes y llamadas
                                                    </td>
                                                </>
                                            ) : !row.billed ? (
                                                // Medidor informativo: el plan no
                                                // lo factura, así que no hay
                                                // excedente que cobrar.
                                                <>
                                                    <td className="py-2 pr-4 tabular-nums">
                                                        {formatNumber(
                                                            row.consumed,
                                                        )}
                                                    </td>
                                                    <td
                                                        className="py-2 pr-4 text-2xs text-fg-3"
                                                        colSpan={3}
                                                    >
                                                        {row.billedVia ===
                                                        'messaging'
                                                            ? 'Se cobra en Mensajería y llamadas (costo real)'
                                                            : 'No incluido · sin costo'}
                                                    </td>
                                                </>
                                            ) : (
                                                <>
                                                    <td className="py-2 pr-4 tabular-nums">
                                                        {formatNumber(
                                                            row.consumed,
                                                        )}
                                                    </td>
                                                    <td className="py-2 pr-4 tabular-nums">
                                                        {formatNumber(
                                                            row.included,
                                                        )}
                                                    </td>
                                                    <td className="py-2 pr-4">
                                                        <UsageBar row={row} />
                                                    </td>
                                                    <td
                                                        className={`py-2 pr-4 tabular-nums ${row.overageCharged && row.overage > 0 ? 'font-semibold text-severity-critical' : ''}`}
                                                    >
                                                        {formatNumber(
                                                            row.overage,
                                                        )}
                                                    </td>
                                                </>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </CardContent>
                </Card>

                {/* Features */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm uppercase">
                            Funcionalidades ({features.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {features.length === 0 ? (
                            <p className="text-xs text-fg-3">
                                Tu plan aplica tal cual: no hay funcionalidades
                                activadas o desactivadas a la medida para tu
                                equipo.
                            </p>
                        ) : (
                            <div className="flex flex-wrap gap-2">
                                {features.map((feature) => (
                                    <Badge
                                        key={feature.key}
                                        variant="outline"
                                        title={
                                            feature.enabled
                                                ? (feature.sourceLabel ??
                                                  undefined)
                                                : 'Desactivada'
                                        }
                                        className={
                                            feature.enabled
                                                ? 'text-severity-low'
                                                : 'text-fg-3 line-through'
                                        }
                                    >
                                        {featureLabel(feature.key)}
                                    </Badge>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Invoices */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm uppercase">
                            Facturas ({invoices.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {invoices.length === 0 ? (
                            <p className="text-xs text-fg-3">
                                Tus facturas aparecerán aquí al cierre de cada
                                periodo de facturación.
                            </p>
                        ) : (
                            <table className="w-full text-left text-xs">
                                <thead className="text-2xs text-fg-3 uppercase">
                                    <tr>
                                        <th className="py-1.5 pr-4">Periodo</th>
                                        <th className="py-1.5 pr-4">
                                            Subtotal
                                        </th>
                                        <th className="py-1.5 pr-4">
                                            Excedentes
                                        </th>
                                        <th className="py-1.5 pr-4">Total</th>
                                        <th className="py-1.5 pr-4">Estado</th>
                                        <th className="py-1.5 pr-4">Pago</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {invoices.map((invoice) => (
                                        <tr
                                            key={invoice.id}
                                            className="border-t border-border/50 text-fg-2"
                                        >
                                            <td className="py-2 pr-4 whitespace-nowrap">
                                                {formatDate(
                                                    invoice.periodStart,
                                                )}
                                                {' — '}
                                                {formatDate(invoice.periodEnd)}
                                            </td>
                                            <td className="py-2 pr-4 tabular-nums">
                                                {money(
                                                    invoice.subtotal,
                                                    invoice.currency,
                                                )}
                                            </td>
                                            <td className="py-2 pr-4 tabular-nums">
                                                {money(
                                                    invoice.overageTotal,
                                                    invoice.currency,
                                                )}
                                            </td>
                                            <td className="py-2 pr-4 font-semibold text-fg-1 tabular-nums">
                                                {money(
                                                    invoice.total,
                                                    invoice.currency,
                                                )}
                                            </td>
                                            <td className="py-2 pr-4">
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        INVOICE_STATUS_COLOR[
                                                            invoice.status
                                                        ] ?? 'text-fg-3'
                                                    }
                                                >
                                                    {invoiceStatusLabel(
                                                        invoice.status,
                                                    )}
                                                </Badge>
                                            </td>
                                            <td className="py-2 pr-4">
                                                <ReceiptUploader
                                                    invoice={invoice}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </CardContent>
                </Card>

                {/* Contacto (F1.2 + B1): el pago es por transferencia
                    bancaria, así que el camino accionable es humano, no un
                    checkout. Franja delgada al pie, no una Card a todo el alto. */}
                <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface-1 px-4 py-3 text-xs text-fg-2 sm:flex-row sm:items-center sm:justify-between">
                    {invoices.length > 0 ? (
                        <p className="text-fg-3">
                            El pago es por transferencia: emitimos tu factura,
                            subes el comprobante arriba y SAM confirma el pago.
                            ¿Algo no cuadra con montos, consumo o datos
                            bancarios? Escríbenos.
                        </p>
                    ) : (
                        <EmptyState
                            className="min-h-0 items-start gap-0.5 px-0 py-0 text-left"
                            title="Todavía no hay facturas"
                            description="Cuando SAM emita tu primera factura aparecerá aquí con su botón para subir el comprobante de transferencia."
                        />
                    )}
                    <div className="flex flex-shrink-0 flex-wrap items-center gap-2">
                        {mailtoHref && (
                            <Button size="sm" variant="outline" asChild>
                                <a href={mailtoHref}>
                                    <Mail size={13} />
                                    Escríbenos
                                </a>
                            </Button>
                        )}
                        {currentTeam && (
                            <Button size="sm" variant="ghost" asChild>
                                <Link
                                    href={`/settings/teams/${currentTeam.id}`}
                                >
                                    <Users size={13} />
                                    Administrar mi equipo
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

BillingIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Facturación',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/billing`
                : '/billing',
        },
    ],
});
