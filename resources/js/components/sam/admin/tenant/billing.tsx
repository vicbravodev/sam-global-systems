import { router } from '@inertiajs/react';
import { FileText, Receipt } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { SubscriptionPill } from '@/components/sam/admin-tenant-status';
import { BillingPill } from '@/components/sam/billing/panel';
import type { BillingTone } from '@/components/sam/billing/panel';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import { Meter } from '@/components/sam/meter';
import { Panel } from '@/components/sam/panel';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatCurrency, formatDate } from '@/lib/format';
import { billingCycleLabel, invoiceStatusLabel } from '@/lib/labels';
import { cn } from '@/lib/utils';
import { update as updateBillingTerms } from '@/routes/admin/tenants/billing-terms';
import { update as updateFeature } from '@/routes/admin/tenants/features';
import {
    generate as generateInvoice,
    markPaid as markInvoicePaid,
    voidMethod as voidInvoice,
} from '@/routes/admin/tenants/invoices';
import {
    cancel as cancelSubscription,
    reactivate as reactivateSubscription,
    suspend as suspendSubscription,
    update as updateSubscription,
} from '@/routes/admin/tenants/subscription';

export interface SubscriptionInfo {
    status: string;
    plan: string | null;
    billingCycle: string | null;
    startsAt: string | null;
    renewsAt: string | null;
}

export interface AssetUsage {
    limit: number | null;
    current: number;
    pending: number;
    excluded: number;
}

export interface BillingTerms {
    unit_price: number;
    currency: string;
    included_assets: number | null;
    min_billable_assets: number;
    ai_fair_use_per_asset: number;
    ai_overage_unit_price: number;
    messaging_markup_percent: number | null;
    fx_usd_rate: number;
    explicit: boolean;
}

export interface BillingDefaults {
    currency: string;
    unit_price: number;
    min_billable_assets: number;
    ai_fair_use_per_asset: number;
    ai_overage_unit_price: number;
    fx_usd_rate: number;
}

export interface InvoiceRow {
    id: number;
    periodStart: string | null;
    periodEnd: string | null;
    total: number;
    currency: string;
    status: string;
    hasReceipt: boolean;
    paidAt: string | null;
}

export interface PlanOption {
    code: string;
    name: string;
}

interface Pending {
    title: string;
    description: string;
    confirmLabel: string;
    tone: 'destructive' | 'default';
    run: () => Promise<void>;
}

/** Envuelve una visita Inertia en una promesa para el spinner del confirm. */
function visit(
    method: 'post' | 'put',
    url: string,
    data: Record<string, unknown> = {},
): Promise<void> {
    return new Promise((resolve) => {
        router[method](url, data as never, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => resolve(),
        });
    });
}

const OPERATIONAL = ['active', 'past_due'];

const INVOICE_TONE: Record<string, BillingTone> = {
    draft: 'neutral',
    finalized: 'warn',
    invoiced: 'warn',
    disputed: 'critical',
    paid: 'ok',
    void: 'neutral',
};

export function CapMeterBar({ usage }: { usage: AssetUsage }) {
    const limit = usage.limit;
    const over = limit !== null && usage.current > limit;

    return (
        <div className="grid gap-2">
            <div className="flex items-baseline gap-2">
                <span className="text-2xl font-semibold tabular-nums">
                    {usage.current}
                </span>
                <span className="text-sm text-fg-3">
                    {limit === null ? 'vigiladas, sin tope' : `de ${limit}`}
                </span>
            </div>
            {limit !== null ? (
                <Meter
                    value={usage.current}
                    max={limit}
                    label="Unidades vigiladas contra el tope"
                    toneClassName={over ? 'bg-severity-medium' : 'bg-primary'}
                />
            ) : null}
            <p
                className={cn(
                    'text-xs',
                    over ? 'text-severity-medium' : 'text-fg-3',
                )}
            >
                {over && limit !== null
                    ? `${usage.current - limit} por encima del tope: se cobran como extra por día (tope suave).`
                    : `${usage.pending} sin decidir · ${usage.excluded} excluidas. El cliente elige cuáles vigilar.`}
            </p>
        </div>
    );
}

const TERM_FIELDS: {
    key: keyof TermsForm;
    label: string;
    step: string;
    hint?: string;
}[] = [
    { key: 'unit_price', label: 'Precio por tracto al mes', step: '0.01' },
    { key: 'currency', label: 'Moneda (ISO)', step: '' },
    {
        key: 'included_assets',
        label: 'Tope contratado',
        step: '1',
        hint: 'Tractos; el excedente se cobra.',
    },
    { key: 'min_billable_assets', label: 'Mínimo facturable', step: '1' },
    {
        key: 'ai_fair_use_per_asset',
        label: 'IA incluida por tracto',
        step: '1',
    },
    {
        key: 'ai_overage_unit_price',
        label: 'IA extra por evaluación',
        step: '0.01',
    },
    {
        key: 'messaging_markup_percent',
        label: 'Margen sobre Twilio (%)',
        step: '0.01',
    },
    { key: 'fx_usd_rate', label: 'Tipo de cambio USD', step: '0.01' },
];

type TermsForm = {
    unit_price: string;
    currency: string;
    included_assets: string;
    min_billable_assets: string;
    ai_fair_use_per_asset: string;
    ai_overage_unit_price: string;
    messaging_markup_percent: string;
    fx_usd_rate: string;
};

function termsForm(terms: BillingTerms): TermsForm {
    const own = (value: number) => (terms.explicit ? String(value) : '');

    return {
        unit_price: own(terms.unit_price),
        currency: terms.explicit ? terms.currency : '',
        included_assets:
            terms.included_assets !== null ? String(terms.included_assets) : '',
        min_billable_assets: own(terms.min_billable_assets),
        ai_fair_use_per_asset: own(terms.ai_fair_use_per_asset),
        ai_overage_unit_price: own(terms.ai_overage_unit_price),
        messaging_markup_percent:
            terms.messaging_markup_percent !== null
                ? String(terms.messaging_markup_percent)
                : '',
        fx_usd_rate: own(terms.fx_usd_rate),
    };
}

export function AdminTenantBilling({
    slug,
    name,
    subscription,
    plans,
    assetUsage,
    assetFeature,
    billingTerms,
    billingDefaults,
    invoices,
}: {
    slug: string;
    name: string;
    subscription: SubscriptionInfo | null;
    plans: PlanOption[];
    assetUsage: AssetUsage;
    assetFeature: { enabled: boolean; limit: number | null } | null;
    billingTerms: BillingTerms;
    billingDefaults: BillingDefaults;
    invoices: InvoiceRow[];
}) {
    const [pending, setPending] = useState<Pending | null>(null);
    const [planCode, setPlanCode] = useState('');
    const [terms, setTerms] = useState<TermsForm>(() =>
        termsForm(billingTerms),
    );
    const [savingTerms, setSavingTerms] = useState(false);
    const [termErrors, setTermErrors] = useState<Record<string, string>>({});
    const [capInput, setCapInput] = useState(
        assetFeature?.limit != null ? String(assetFeature.limit) : '',
    );

    const status = subscription?.status ?? null;
    const operational = status !== null && OPERATIONAL.includes(status);

    const confirm = (p: Pending) => setPending(p);

    const saveTerms = (e: FormEvent) => {
        e.preventDefault();
        const num = (v: string) => (v.trim() === '' ? null : Number(v));

        router.put(
            updateBillingTerms(slug).url,
            {
                unit_price: num(terms.unit_price),
                currency:
                    terms.currency.trim() === ''
                        ? null
                        : terms.currency.trim().toLowerCase(),
                included_assets: num(terms.included_assets),
                min_billable_assets: num(terms.min_billable_assets),
                ai_fair_use_per_asset: num(terms.ai_fair_use_per_asset),
                ai_overage_unit_price: num(terms.ai_overage_unit_price),
                messaging_markup_percent: num(terms.messaging_markup_percent),
                fx_usd_rate: num(terms.fx_usd_rate),
            },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSavingTerms(true),
                onFinish: () => setSavingTerms(false),
                onSuccess: () => setTermErrors({}),
                onError: (errors) => setTermErrors(errors),
            },
        );
    };

    const saveCap = (limit: number | null) =>
        confirm({
            title:
                limit === null
                    ? 'Quitar el tope de unidades'
                    : `Fijar el tope en ${limit} unidades`,
            description:
                limit === null
                    ? `${name} podrá vigilar unidades sin tope de override (aplica el de sus términos o plan).`
                    : `Por encima de ${limit} unidades vigiladas, ${name} paga el excedente por día. No bloquea.`,
            confirmLabel: 'Guardar tope',
            tone: 'default',
            run: () =>
                visit(
                    'put',
                    updateFeature({
                        team: slug,
                        featureKey: 'monitored_assets',
                    }).url,
                    {
                        enabled: assetFeature?.enabled ?? true,
                        included_quantity: limit,
                    },
                ),
        });

    return (
        <div className="grid gap-4 xl:grid-cols-2">
            <Panel
                size="lg"
                bodyClassName="p-4"
                title="Suscripción"
                description="Estado comercial del cliente. Suspender corta su acceso operativo."
                action={<SubscriptionPill status={status} />}
            >
                {subscription ? (
                    <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-sm">
                        <dt className="text-fg-3">Plan</dt>
                        <dd>{subscription.plan ?? 'Sin plan'}</dd>
                        <dt className="text-fg-3">Ciclo</dt>
                        <dd>{billingCycleLabel(subscription.billingCycle)}</dd>
                        <dt className="text-fg-3">Inicio</dt>
                        <dd className="tabular-nums">
                            {formatDate(subscription.startsAt)}
                        </dd>
                        <dt className="text-fg-3">Renueva</dt>
                        <dd className="tabular-nums">
                            {formatDate(subscription.renewsAt)}
                        </dd>
                    </dl>
                ) : (
                    <p className="text-sm text-fg-3">
                        Sin suscripción: se cobra con sus términos por
                        tracto-día. Asigna un plan si quieres topes de plan.
                    </p>
                )}

                <div className="mt-4 grid gap-3 border-t border-border pt-4">
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="grid min-w-48 flex-1 gap-1.5">
                            <Label htmlFor="plan-select">
                                {subscription ? 'Cambiar plan' : 'Asignar plan'}
                            </Label>
                            <Select
                                value={planCode}
                                onValueChange={setPlanCode}
                            >
                                <SelectTrigger
                                    id="plan-select"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Elige un plan" />
                                </SelectTrigger>
                                <SelectContent>
                                    {plans.map((plan) => (
                                        <SelectItem
                                            key={plan.code}
                                            value={plan.code}
                                        >
                                            {plan.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!planCode}
                            onClick={() => {
                                const plan = plans.find(
                                    (p) => p.code === planCode,
                                );
                                confirm({
                                    title: `Aplicar el plan ${plan?.name ?? ''}`,
                                    description: `Los topes de ${name} pasan a los del plan elegido.`,
                                    confirmLabel: 'Aplicar plan',
                                    tone: 'default',
                                    run: () =>
                                        visit(
                                            'put',
                                            updateSubscription(slug).url,
                                            {
                                                plan_code: planCode,
                                            },
                                        ).then(() => setPlanCode('')),
                                });
                            }}
                        >
                            Aplicar
                        </Button>
                    </div>

                    {subscription ? (
                        <div className="flex flex-wrap gap-2">
                            {operational ? (
                                <>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            confirm({
                                                title: `Suspender a ${name}`,
                                                description:
                                                    'Su equipo pierde el acceso operativo hasta que lo reactives. No se borra nada.',
                                                confirmLabel: 'Suspender',
                                                tone: 'destructive',
                                                run: () =>
                                                    visit(
                                                        'post',
                                                        suspendSubscription(
                                                            slug,
                                                        ).url,
                                                    ),
                                            })
                                        }
                                    >
                                        Suspender
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="text-destructive"
                                        onClick={() =>
                                            confirm({
                                                title: `Dar de baja la suscripción de ${name}`,
                                                description:
                                                    'La suscripción queda cancelada y el cliente sin acceso. Para volver hay que reactivarla.',
                                                confirmLabel: 'Dar de baja',
                                                tone: 'destructive',
                                                run: () =>
                                                    visit(
                                                        'post',
                                                        cancelSubscription(slug)
                                                            .url,
                                                    ),
                                            })
                                        }
                                    >
                                        Dar de baja
                                    </Button>
                                </>
                            ) : (
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        confirm({
                                            title: `Reactivar a ${name}`,
                                            description:
                                                'Su equipo recupera el acceso operativo de inmediato.',
                                            confirmLabel: 'Reactivar',
                                            tone: 'default',
                                            run: () =>
                                                visit(
                                                    'post',
                                                    reactivateSubscription(slug)
                                                        .url,
                                                ),
                                        })
                                    }
                                >
                                    Reactivar
                                </Button>
                            )}
                        </div>
                    ) : null}
                </div>
            </Panel>

            <Panel
                size="lg"
                bodyClassName="p-4"
                title="Unidades vigiladas"
                description="Lo que se cobra: sólo las unidades vigiladas, por día."
            >
                <CapMeterBar usage={assetUsage} />

                {assetFeature ? (
                    <form
                        className="mt-4 flex flex-wrap items-end gap-2 border-t border-border pt-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            const value = capInput.trim();
                            saveCap(
                                value === ''
                                    ? null
                                    : Math.max(0, Math.floor(Number(value))),
                            );
                        }}
                    >
                        <div className="grid min-w-40 flex-1 gap-1.5">
                            <Label htmlFor="asset-limit">
                                Tope manual (override)
                            </Label>
                            <Input
                                id="asset-limit"
                                type="number"
                                inputMode="numeric"
                                min={0}
                                value={capInput}
                                onChange={(e) => setCapInput(e.target.value)}
                                placeholder="Vacío = sin override"
                            />
                        </div>
                        <Button type="submit" size="sm" variant="outline">
                            Guardar tope
                        </Button>
                    </form>
                ) : null}
            </Panel>

            <Panel
                size="lg"
                bodyClassName="p-4"
                title="Términos de cobro"
                description={
                    billingTerms.explicit
                        ? 'Este cliente tiene términos propios. Vacío = default de la plataforma.'
                        : 'Usa los defaults de la plataforma. Llena sólo lo que cambie.'
                }
                className="xl:col-span-2"
            >
                <form onSubmit={saveTerms} className="grid gap-4">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {TERM_FIELDS.map((field) => (
                            <div key={field.key} className="grid gap-1.5">
                                <Label htmlFor={`terms-${field.key}`}>
                                    {field.label}
                                </Label>
                                <Input
                                    id={`terms-${field.key}`}
                                    type={
                                        field.key === 'currency'
                                            ? 'text'
                                            : 'number'
                                    }
                                    min={0}
                                    step={field.step || undefined}
                                    maxLength={
                                        field.key === 'currency' ? 3 : undefined
                                    }
                                    value={terms[field.key]}
                                    onChange={(e) =>
                                        setTerms((prev) => ({
                                            ...prev,
                                            [field.key]: e.target.value,
                                        }))
                                    }
                                    placeholder={defaultPlaceholder(
                                        field.key,
                                        billingDefaults,
                                    )}
                                />
                                {termErrors[field.key] ? (
                                    <p className="text-xs text-destructive">
                                        {termErrors[field.key]}
                                    </p>
                                ) : field.hint ? (
                                    <p className="text-xs text-fg-3">
                                        {field.hint}
                                    </p>
                                ) : null}
                            </div>
                        ))}
                    </div>
                    <div className="flex justify-end">
                        <Button type="submit" size="sm" disabled={savingTerms}>
                            Guardar términos
                        </Button>
                    </div>
                </form>
            </Panel>

            <Panel
                size="lg"
                bodyClassName="p-4"
                title="Facturas"
                description="Cobro por transferencia. El día 1 se cierra el mes anterior en automático."
                className="xl:col-span-2"
                action={
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            confirm({
                                title: 'Generar la factura del mes anterior',
                                description: `Se recalculan los contadores de ${name} y se emite la factura. Si ya existe, no se duplica.`,
                                confirmLabel: 'Generar',
                                tone: 'default',
                                run: () =>
                                    visit('post', generateInvoice(slug).url),
                            })
                        }
                    >
                        <FileText className="size-3.5" />
                        Generar factura
                    </Button>
                }
            >
                {invoices.length === 0 ? (
                    <EmptyState
                        icon={Receipt}
                        title="Sin facturas todavía"
                        description="La primera se emite al cerrar el primer mes con unidades vigiladas."
                        className="py-6"
                    />
                ) : (
                    <ul className="divide-y divide-border">
                        {invoices.map((invoice) => {
                            const open =
                                invoice.status !== 'paid' &&
                                invoice.status !== 'void';

                            return (
                                <li
                                    key={invoice.id}
                                    className="flex flex-wrap items-center gap-x-4 gap-y-2 py-2.5 text-sm"
                                >
                                    <span className="font-mono text-xs text-fg-3">
                                        #{invoice.id}
                                    </span>
                                    <span className="tabular-nums">
                                        {formatDate(invoice.periodStart)} a{' '}
                                        {formatDate(invoice.periodEnd)}
                                    </span>
                                    <span className="font-semibold tabular-nums">
                                        {formatCurrency(
                                            invoice.total,
                                            invoice.currency,
                                        )}
                                    </span>
                                    <BillingPill
                                        tone={
                                            INVOICE_TONE[invoice.status] ??
                                            'neutral'
                                        }
                                    >
                                        {invoiceStatusLabel(invoice.status)}
                                    </BillingPill>
                                    {invoice.hasReceipt ? (
                                        <span className="text-xs text-fg-3">
                                            Comprobante recibido
                                        </span>
                                    ) : null}
                                    {invoice.paidAt ? (
                                        <span className="text-xs text-fg-3">
                                            Pagada el{' '}
                                            {formatDate(invoice.paidAt)}
                                        </span>
                                    ) : null}
                                    {open ? (
                                        <span className="ml-auto flex gap-1">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    confirm({
                                                        title: `Marcar pagada la factura #${invoice.id}`,
                                                        description: `Confirma que recibiste la transferencia de ${formatCurrency(invoice.total, invoice.currency)}.`,
                                                        confirmLabel:
                                                            'Marcar pagada',
                                                        tone: 'default',
                                                        run: () =>
                                                            visit(
                                                                'post',
                                                                markInvoicePaid(
                                                                    {
                                                                        team: slug,
                                                                        invoice:
                                                                            invoice.id,
                                                                    },
                                                                ).url,
                                                            ),
                                                    })
                                                }
                                            >
                                                Marcar pagada
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    confirm({
                                                        title: `Anular la factura #${invoice.id}`,
                                                        description:
                                                            'Una factura anulada no se puede reabrir ni marcar como pagada.',
                                                        confirmLabel: 'Anular',
                                                        tone: 'destructive',
                                                        run: () =>
                                                            visit(
                                                                'post',
                                                                voidInvoice({
                                                                    team: slug,
                                                                    invoice:
                                                                        invoice.id,
                                                                }).url,
                                                            ),
                                                    })
                                                }
                                            >
                                                Anular
                                            </Button>
                                        </span>
                                    ) : null}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Panel>

            <ConfirmDialog
                open={pending !== null}
                title={pending?.title ?? ''}
                description={pending?.description ?? ''}
                confirmLabel={pending?.confirmLabel}
                tone={pending?.tone}
                onOpenChange={(open) => !open && setPending(null)}
                onConfirm={async () => {
                    await pending?.run();
                    setPending(null);
                }}
            />
        </div>
    );
}

function defaultPlaceholder(key: keyof TermsForm, d: BillingDefaults): string {
    switch (key) {
        case 'unit_price':
            return `${d.unit_price}`;
        case 'currency':
            return d.currency.toUpperCase();
        case 'min_billable_assets':
            return `${d.min_billable_assets}`;
        case 'ai_fair_use_per_asset':
            return `${d.ai_fair_use_per_asset}`;
        case 'ai_overage_unit_price':
            return `${d.ai_overage_unit_price}`;
        case 'fx_usd_rate':
            return `${d.fx_usd_rate}`;
        case 'included_assets':
            return 'Sin tope';
        default:
            return 'Default';
    }
}
