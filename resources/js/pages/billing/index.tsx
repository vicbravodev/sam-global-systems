import { Head, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatCurrency } from '@/lib/format';

interface SubscriptionProp {
    planName: string | null;
    planCode: string | null;
    basePrice: number | null;
    currency: string | null;
    billingCycle: string;
    status: string;
    renewsAt: string | null;
    trialEndsAt: string | null;
}

interface FeatureRow {
    key: string;
    enabled: boolean;
    source: string;
    limits: Record<string, unknown> | null;
}

interface UsageRow {
    meterCode: string | null;
    meterName: string | null;
    unit: string | null;
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
    paidAt: string | null;
    hasReceipt: boolean;
    paymentNote: string | null;
    breakdown: Record<string, unknown> | null;
}

interface BillingPageProps {
    subscription: SubscriptionProp | null;
    features: FeatureRow[];
    usage: UsageRow[];
    invoices: InvoiceRow[];
}

const SUBSCRIPTION_STATUS_COLOR: Record<string, string> = {
    active: 'text-severity-low',
    trial: 'text-severity-medium',
    suspended: 'text-severity-critical',
    cancelled: 'text-fg-3',
};

function money(value: number, currency: string | null): string {
    return formatCurrency(value, currency);
}

function UsageBar({ row }: { row: UsageRow }) {
    const ratio =
        row.included > 0 ? Math.min(1, row.consumed / row.included) : 0;
    const over = row.overage > 0;

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
            <span className="text-[11px] text-severity-low">
                Pagada
                {invoice.paidAt &&
                    ` el ${new Date(invoice.paidAt).toLocaleDateString('es')}`}
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
                    'Comprobante enviado — el equipo de SAM lo verificará.',
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
                className="text-[11px] text-fg-2 underline hover:text-fg-1"
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
    const { subscription, features, usage, invoices } =
        page.props as unknown as BillingPageProps;

    return (
        <>
            <Head title="Facturación" />
            <div className="flex flex-col gap-4 p-5">
                <div>
                    <h1 className="text-[16px] font-semibold text-fg-1">
                        Facturación
                    </h1>
                    <p className="text-[12px] text-fg-3">
                        Plan, consumo del periodo y facturas. El pago es por
                        transferencia bancaria — contacta a soporte para cambios
                        de plan.
                    </p>
                </div>

                {/* Plan */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-[13px] uppercase">
                            Plan actual
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="text-[13px] text-fg-2">
                        {subscription === null ? (
                            <p className="text-fg-3">
                                Tu equipo todavía no tiene un plan activo. El
                                equipo de SAM lo activa al confirmar tu pago;
                                escríbenos si ya realizaste la transferencia.
                            </p>
                        ) : (
                            <div className="flex flex-wrap items-center gap-3">
                                <span className="text-[18px] font-semibold text-fg-1">
                                    {subscription.planName ?? '—'}
                                </span>
                                <Badge
                                    variant="outline"
                                    className={
                                        SUBSCRIPTION_STATUS_COLOR[
                                            subscription.status
                                        ] ?? 'text-fg-3'
                                    }
                                >
                                    {subscription.status}
                                </Badge>
                                {subscription.basePrice !== null && (
                                    <span>
                                        {money(
                                            subscription.basePrice,
                                            subscription.currency,
                                        )}{' '}
                                        / {subscription.billingCycle}
                                    </span>
                                )}
                                {subscription.renewsAt && (
                                    <span className="text-fg-3">
                                        Renueva el{' '}
                                        {new Date(
                                            subscription.renewsAt,
                                        ).toLocaleDateString('es')}
                                    </span>
                                )}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Usage */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-[13px] uppercase">
                            Consumo del periodo
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {usage.length === 0 ? (
                            <p className="text-[12px] text-fg-3">
                                El consumo aparecerá aquí en cuanto tu operación
                                genere actividad este periodo (eventos, media,
                                llamadas de verificación).
                            </p>
                        ) : (
                            <table className="w-full text-left text-[12px]">
                                <thead className="text-[11px] text-fg-3 uppercase">
                                    <tr>
                                        <th className="py-1.5 pr-4">Meter</th>
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
                                                    {row.meterName ??
                                                        row.meterCode}
                                                </span>
                                                <span className="ml-1 text-[11px] text-fg-3">
                                                    ({row.unit})
                                                </span>
                                            </td>
                                            <td className="py-2 pr-4 tabular-nums">
                                                {row.consumed.toLocaleString(
                                                    'es',
                                                )}
                                            </td>
                                            <td className="py-2 pr-4 tabular-nums">
                                                {row.included.toLocaleString(
                                                    'es',
                                                )}
                                            </td>
                                            <td className="py-2 pr-4">
                                                <UsageBar row={row} />
                                            </td>
                                            <td
                                                className={`py-2 pr-4 tabular-nums ${row.overage > 0 ? 'font-semibold text-severity-critical' : ''}`}
                                            >
                                                {row.overage.toLocaleString(
                                                    'es',
                                                )}
                                            </td>
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
                        <CardTitle className="text-[13px] uppercase">
                            Funcionalidades ({features.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {features.length === 0 ? (
                            <p className="text-[12px] text-fg-3">
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
                                        className={
                                            feature.enabled
                                                ? 'text-severity-low'
                                                : 'text-fg-3 line-through'
                                        }
                                    >
                                        {feature.key}
                                    </Badge>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Invoices */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-[13px] uppercase">
                            Facturas ({invoices.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {invoices.length === 0 ? (
                            <p className="text-[12px] text-fg-3">
                                Tus facturas aparecerán aquí al cierre de cada
                                periodo de facturación.
                            </p>
                        ) : (
                            <table className="w-full text-left text-[12px]">
                                <thead className="text-[11px] text-fg-3 uppercase">
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
                                                {invoice.periodStart
                                                    ? new Date(
                                                          invoice.periodStart,
                                                      ).toLocaleDateString('es')
                                                    : '—'}
                                                {' — '}
                                                {invoice.periodEnd
                                                    ? new Date(
                                                          invoice.periodEnd,
                                                      ).toLocaleDateString('es')
                                                    : '—'}
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
                                                    className="text-fg-3"
                                                >
                                                    {invoice.status}
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
