import { ChevronDown, FileText, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { Panel } from '@/components/sam/panel';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { APP_LOCALE, formatDate, formatNumber, toDate } from '@/lib/format';
import { meterLabel } from '@/lib/labels';
import { postFormData } from '@/lib/sam-fetch';
import { submit } from '@/lib/submit';
import { cn } from '@/lib/utils';
import billingRoutes from '@/routes/billing';
import type { BillingTone } from './panel';
import { BillingPill, money } from './panel';
import type { InvoiceLine, InvoiceRow } from './types';

const VISIBLE_INVOICES = 4;

const CORE_MODELS = new Set(['asset_day', 'fair_use', 'cost_plus']);

function periodLabel(invoice: InvoiceRow): string {
    if (!invoice.periodStart) {
        return 'Periodo sin fecha';
    }

    const label = toDate(invoice.periodStart).toLocaleDateString(APP_LOCALE, {
        month: 'long',
        year: 'numeric',
    });

    return label.charAt(0).toUpperCase() + label.slice(1);
}

/** Estado de pago en palabras del cliente. */
function paymentState(invoice: InvoiceRow): {
    tone: BillingTone;
    label: string;
} {
    if (invoice.status === 'paid') {
        return { tone: 'ok', label: 'Pagada' };
    }

    if (invoice.status === 'void') {
        return { tone: 'neutral', label: 'Anulada' };
    }

    if (invoice.status === 'disputed') {
        return { tone: 'warn', label: 'En aclaración' };
    }

    if (invoice.awaitsPayment) {
        return invoice.hasReceipt
            ? { tone: 'info', label: 'Comprobante en revisión' }
            : { tone: 'warn', label: 'Por pagar' };
    }

    return { tone: 'neutral', label: 'En preparación' };
}

function breakdownLines(invoice: InvoiceRow): InvoiceLine[] {
    const raw = invoice.breakdown;

    if (!Array.isArray(raw)) {
        return [];
    }

    return raw.filter(
        (line) =>
            typeof line === 'object' &&
            line !== null &&
            line.meter_code !== '_terms' &&
            // Los medidores informativos del plan sin cargo no aportan nada
            // al desglose; las tres líneas del modelo siempre se muestran.
            (CORE_MODELS.has(String(line.billing_model)) ||
                Number(line.amount ?? 0) > 0),
    );
}

function lineText(line: InvoiceLine): { concept: string; detail: string } {
    const consumed = Number(line.consumed ?? 0);
    const included = Number(line.included ?? 0);
    const overage = Number(line.overage ?? 0);

    if (line.billing_model === 'asset_day') {
        return {
            concept: 'Vigilancia de unidades',
            detail:
                overage > 0
                    ? `${formatNumber(consumed)} tracto-días, ${formatNumber(overage)} por encima del tope`
                    : `${formatNumber(consumed)} tracto-días`,
        };
    }

    if (line.billing_model === 'fair_use') {
        return {
            concept: 'Evaluaciones de IA extra',
            detail:
                overage > 0
                    ? `${formatNumber(overage)} por encima de las ${formatNumber(included)} incluidas`
                    : `${formatNumber(consumed)} de ${formatNumber(included)} incluidas: sin costo extra`,
        };
    }

    if (line.billing_model === 'cost_plus') {
        return {
            concept: 'Mensajería y llamadas',
            detail: 'SMS, WhatsApp y llamadas: costo real más margen',
        };
    }

    return {
        concept: meterLabel(line.meter_code, line.meter_name),
        detail:
            overage > 0
                ? `${formatNumber(overage)} por encima de ${formatNumber(included)} incluidos`
                : `${formatNumber(consumed)} de ${formatNumber(included)} incluidos`,
    };
}

function ReceiptButton({
    invoice,
    teamSlug,
}: {
    invoice: InvoiceRow;
    teamSlug: string | null;
}) {
    const inputRef = useRef<HTMLInputElement | null>(null);
    const [uploading, setUploading] = useState(false);

    const upload = async (file: File) => {
        if (teamSlug === null) {
            return;
        }

        setUploading(true);

        const body = new FormData();
        body.append('receipt', file);

        try {
            await submit(
                postFormData(
                    billingRoutes.invoices.receipt.url([teamSlug, invoice.id]),
                    body,
                ),
                'Comprobante enviado. El equipo de SAM lo verificará.',
                {
                    forbiddenMessage:
                        'No tienes permisos para subir comprobantes.',
                    errorMessage: 'No se pudo subir el comprobante.',
                    only: ['invoices'],
                },
            );
        } finally {
            setUploading(false);

            if (inputRef.current) {
                inputRef.current.value = '';
            }
        }
    };

    return (
        <>
            <Button
                size="sm"
                variant={invoice.hasReceipt ? 'ghost' : 'outline'}
                disabled={uploading}
                onClick={() => inputRef.current?.click()}
            >
                <Upload size={13} />
                {uploading
                    ? 'Subiendo…'
                    : invoice.hasReceipt
                      ? 'Reemplazar comprobante'
                      : 'Subir comprobante'}
            </Button>
            <input
                ref={inputRef}
                type="file"
                accept=".pdf,image/*"
                className="hidden"
                onChange={(event) => {
                    const file = event.target.files?.[0];

                    if (file) {
                        void upload(file);
                    }
                }}
            />
        </>
    );
}

function InvoiceItem({
    invoice,
    teamSlug,
}: {
    invoice: InvoiceRow;
    teamSlug: string | null;
}) {
    const [open, setOpen] = useState(false);
    const state = paymentState(invoice);
    const lines = breakdownLines(invoice);

    return (
        <li className="flex flex-col">
            <div className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:gap-4">
                <button
                    type="button"
                    onClick={() => setOpen((value) => !value)}
                    aria-expanded={open}
                    disabled={lines.length === 0}
                    className="flex w-full min-w-0 flex-1 items-center gap-2.5 text-left disabled:cursor-default"
                >
                    <FileText
                        className="size-4 shrink-0 text-fg-3"
                        aria-hidden="true"
                    />
                    <span className="flex min-w-0 flex-col">
                        <span className="text-sm font-medium text-fg-1">
                            {periodLabel(invoice)}
                        </span>
                        <span className="text-2xs text-fg-3">
                            {formatDate(invoice.periodStart)} –{' '}
                            {formatDate(invoice.periodEnd)}
                            {invoice.status === 'paid' &&
                                invoice.paidAt &&
                                ` · pagada el ${formatDate(invoice.paidAt)}`}
                        </span>
                    </span>
                    {lines.length > 0 && (
                        <ChevronDown
                            className={cn(
                                'size-3.5 shrink-0 text-fg-3 transition-transform',
                                open && 'rotate-180',
                            )}
                            aria-hidden="true"
                        />
                    )}
                </button>
                <div className="flex items-center justify-between gap-3 pl-6.5 sm:justify-end sm:pl-0">
                    <BillingPill tone={state.tone}>{state.label}</BillingPill>
                    <span className="text-right text-sm font-semibold whitespace-nowrap text-fg-1 tabular-nums sm:w-32">
                        {money(invoice.total, invoice.currency)}
                    </span>
                </div>
                {invoice.awaitsPayment && (
                    <div className="flex pl-6.5 sm:pl-0">
                        <ReceiptButton invoice={invoice} teamSlug={teamSlug} />
                    </div>
                )}
            </div>
            {invoice.paymentNote && (
                <p className="px-4 pb-2 pl-10.5 text-2xs text-fg-3">
                    Nota de SAM: {invoice.paymentNote}
                </p>
            )}
            {open && lines.length > 0 && (
                <ul className="mx-4 mb-3 flex flex-col divide-y divide-border rounded-md border border-border bg-surface-2">
                    {lines.map((line, index) => {
                        const text = lineText(line);

                        return (
                            <li
                                key={`${line.meter_code ?? 'line'}-${index}`}
                                className="flex items-start justify-between gap-3 px-3 py-2 text-xs"
                            >
                                <span className="min-w-0">
                                    <span className="block text-fg-1">
                                        {text.concept}
                                    </span>
                                    <span className="block text-2xs text-fg-3">
                                        {text.detail}
                                    </span>
                                </span>
                                <span className="whitespace-nowrap text-fg-1 tabular-nums">
                                    {money(
                                        Number(line.amount ?? 0),
                                        invoice.currency,
                                    )}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            )}
        </li>
    );
}

export function InvoiceHistory({
    invoices,
    teamSlug,
}: {
    invoices: InvoiceRow[];
    teamSlug: string | null;
}) {
    const [showAll, setShowAll] = useState(false);
    // Las por pagar siempre a la vista; del historial, sólo las recientes.
    const visible = showAll
        ? invoices
        : invoices.filter(
              (invoice, index) =>
                  index < VISIBLE_INVOICES || invoice.awaitsPayment,
          );
    const hidden = invoices.length - visible.length;
    const pending = invoices.filter(
        (invoice) => invoice.awaitsPayment && !invoice.hasReceipt,
    ).length;

    return (
        <Panel
            title="Facturas"
            description={
                pending > 0
                    ? `${pending === 1 ? 'Tienes 1 factura por pagar' : `Tienes ${pending} facturas por pagar`}: transfiere el total y sube aquí tu comprobante.`
                    : 'Al cierre de cada mes aparece aquí tu factura. Toca una para ver su detalle.'
            }
        >
            {invoices.length === 0 ? (
                <EmptyState
                    className="min-h-0 py-8"
                    icon={FileText}
                    title="Todavía no hay facturas"
                    description="Tu primera factura aparecerá aquí al cierre del mes, con su botón para subir el comprobante de transferencia."
                />
            ) : (
                <ul className="flex flex-col divide-y divide-border">
                    {visible.map((invoice) => (
                        <InvoiceItem
                            key={invoice.id}
                            invoice={invoice}
                            teamSlug={teamSlug}
                        />
                    ))}
                </ul>
            )}
            {(hidden > 0 || showAll) && invoices.length > VISIBLE_INVOICES && (
                <button
                    type="button"
                    onClick={() => setShowAll((value) => !value)}
                    className="border-t border-border px-4 py-2.5 text-left text-xs text-primary hover:bg-surface-2"
                >
                    {showAll
                        ? 'Ver sólo las recientes'
                        : `Ver ${hidden === 1 ? 'la factura anterior' : `las ${hidden} facturas anteriores`}`}
                </button>
            )}
        </Panel>
    );
}
