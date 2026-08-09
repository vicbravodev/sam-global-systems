import { Head, Link, router, usePage } from '@inertiajs/react';
import { Mail, Users } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHeader } from '@/components/ui/page-header';
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
    supportEmail: string | null;
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
            <span className="text-2xs text-severity-low">
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
    const { supportEmail, subscription, features, usage, invoices } =
        page.props as unknown as BillingPageProps;
    const currentTeam = page.props.currentTeam;

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
                    description="Plan, consumo del periodo y facturas. El pago es por transferencia bancaria. Contacta a soporte para cambios de plan."
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
                                    {subscription.status}
                                </Badge>
                            </MetricCell>
                            <MetricCell label="Precio base">
                                <span className="text-base font-semibold text-fg-1">
                                    {subscription.basePrice !== null
                                        ? `${money(subscription.basePrice, subscription.currency)} / ${subscription.billingCycle}`
                                        : '—'}
                                </span>
                            </MetricCell>
                            <MetricCell label="Próxima renovación">
                                <span className="text-base font-semibold text-fg-1">
                                    {subscription.renewsAt
                                        ? new Date(
                                              subscription.renewsAt,
                                          ).toLocaleDateString('es')
                                        : '—'}
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
                                                <span className="ml-1 text-2xs text-fg-3">
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

                {/* Contacto (F1.2 + B1): el pago es por transferencia
                    bancaria, así que el camino accionable es humano, no un
                    checkout. Franja delgada al pie, no una Card a todo el alto. */}
                <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface-1 px-4 py-3 text-xs text-fg-2 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-fg-3">
                        El pago es por transferencia: emitimos tu factura, subes
                        el comprobante arriba y SAM confirma el pago. ¿Algo no
                        cuadra con montos, consumo o datos bancarios?
                        Escríbenos.
                    </p>
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
