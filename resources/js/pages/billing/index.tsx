import { Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, Eye, Mail, MoreHorizontal, Users } from 'lucide-react';
import { CapMeter } from '@/components/sam/billing/cap-meter';
import { HowToPay } from '@/components/sam/billing/how-to-pay';
import { InvoiceHistory } from '@/components/sam/billing/invoice-history';
import { MonthSummary } from '@/components/sam/billing/month-summary';
import type { BillingTone } from '@/components/sam/billing/panel';
import { BillingPill } from '@/components/sam/billing/panel';
import type { BillingPageProps } from '@/components/sam/billing/types';
import { UsageDetail } from '@/components/sam/billing/usage-detail';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { PageHeader } from '@/components/ui/page-header';
import assetRoutes from '@/routes/assets';
import billingRoutes from '@/routes/billing';
import { edit as editTeam } from '@/routes/teams';

/** Estado del servicio en palabras del cliente (el plan no se muestra:
 *  los planes sólo son plantillas de topes, el cobro es por tracto-día). */
const SERVICE_STATE: Record<string, { tone: BillingTone; label: string }> = {
    active: { tone: 'ok', label: 'Servicio activo' },
    past_due: { tone: 'warn', label: 'Pago vencido' },
    suspended: { tone: 'critical', label: 'Servicio suspendido' },
    canceled: { tone: 'neutral', label: 'Servicio cancelado' },
    expired: { tone: 'neutral', label: 'Servicio vencido' },
};

export default function BillingIndex() {
    const page = usePage();
    const {
        supportEmail,
        transfer,
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

    const service = subscription
        ? (SERVICE_STATE[subscription.status] ?? {
              tone: 'neutral' as const,
              label: subscription.statusLabel ?? 'Sin estado',
          })
        : null;
    const serviceAlert =
        subscription === null ||
        subscription.status === 'past_due' ||
        subscription.status === 'suspended';

    return (
        <>
            <Head title="Facturación" />
            <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
                <PageHeader
                    title="Facturación"
                    description="Pagas por cada día que una unidad está vigilada."
                    meta={
                        service && (
                            <BillingPill tone={service.tone}>
                                {service.label}
                            </BillingPill>
                        )
                    }
                    actions={
                        <>
                            {teamSlug && (
                                <Button size="sm" asChild>
                                    <Link href={assetRoutes.index(teamSlug)}>
                                        <Eye size={13} />
                                        Elegir qué unidades vigilar
                                    </Link>
                                </Button>
                            )}
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        size="icon"
                                        variant="outline"
                                        className="size-8"
                                        aria-label="Más opciones"
                                    >
                                        <MoreHorizontal size={15} />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {mailtoHref && (
                                        <DropdownMenuItem asChild>
                                            <a href={mailtoHref}>
                                                <Mail size={13} /> Escribir a
                                                facturación
                                            </a>
                                        </DropdownMenuItem>
                                    )}
                                    {currentTeam && (
                                        <DropdownMenuItem asChild>
                                            <Link
                                                href={editTeam(
                                                    currentTeam.slug,
                                                )}
                                            >
                                                <Users size={13} /> Administrar
                                                mi equipo
                                            </Link>
                                        </DropdownMenuItem>
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </>
                    }
                    className="shrink-0 border-b border-border bg-surface-1 px-5 py-3"
                />

                <div className="min-h-0 flex-1 overflow-y-auto">
                    <div className="mx-auto flex w-full max-w-6xl flex-col gap-4 px-4 py-5 sm:px-5">
                        {serviceAlert && (
                            <div className="flex flex-col gap-2 rounded-lg border border-severity-medium/40 bg-severity-medium/10 px-4 py-3 text-xs text-fg-2 sm:flex-row sm:items-center sm:justify-between">
                                <p className="flex items-start gap-2">
                                    <AlertTriangle
                                        className="mt-0.5 size-3.5 shrink-0 text-severity-medium"
                                        aria-hidden="true"
                                    />
                                    <span>
                                        {subscription === null
                                            ? 'Tu servicio todavía no está activo. El equipo de SAM lo activa al confirmar tu pago; escríbenos si ya hiciste la transferencia.'
                                            : subscription.status ===
                                                'suspended'
                                              ? 'Tu servicio está suspendido. Paga las facturas pendientes y sube el comprobante para reactivarlo.'
                                              : 'Tienes un pago vencido. Transfiere el total pendiente y sube el comprobante en la factura.'}
                                    </span>
                                </p>
                                {mailtoHref && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        asChild
                                        className="self-start sm:self-auto"
                                    >
                                        <a href={mailtoHref}>
                                            <Mail size={13} />
                                            Escríbenos
                                        </a>
                                    </Button>
                                )}
                            </div>
                        )}

                        <div className="grid gap-4 lg:grid-cols-3">
                            <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
                                <MonthSummary estimate={estimate} />
                                <InvoiceHistory
                                    invoices={invoices}
                                    teamSlug={teamSlug}
                                />
                            </div>
                            <div className="flex min-w-0 flex-col gap-4">
                                <CapMeter
                                    estimate={estimate}
                                    terms={terms}
                                    fleet={fleet}
                                    teamSlug={teamSlug}
                                />
                                <HowToPay
                                    transfer={transfer}
                                    mailtoHref={mailtoHref}
                                />
                            </div>
                        </div>

                        <UsageDetail usage={usage} features={features} />
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
                ? billingRoutes.show.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
