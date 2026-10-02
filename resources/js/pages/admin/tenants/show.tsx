import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, UserCog } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { invoiceStatusLabel } from '@/lib/labels';
import { store as impersonateStore } from '@/routes/admin/impersonate';
import { index as adminTenantsIndex } from '@/routes/admin/tenants';

interface TenantBranding {
    displayName: string | null;
    primaryColor: string | null;
    secondaryColor: string | null;
    logoUrl: string | null;
}

interface Tenant {
    id: number;
    name: string;
    slug: string;
    isPersonal: boolean;
    createdAt: string | null;
    branding: TenantBranding;
}

interface Subscription {
    status: string;
    plan: string | null;
    billingCycle: string | null;
    startsAt: string | null;
    renewsAt: string | null;
}

interface Member {
    id: number;
    name: string;
    email: string;
    role: string;
    pendingAccess: boolean;
}

interface Feature {
    key: string;
    enabled: boolean;
    source: string;
    limits: Record<string, unknown> | null;
}

interface UsageRow {
    meter: string;
    periodStart: string | null;
    consumed: number;
    included: number;
    overage: number;
    /** Cost-plus meters (Twilio): USD amounts instead of a micro count. */
    money: { providerCost: number; charged: number } | null;
}

interface PlanOption {
    code: string;
    name: string;
}

interface AssetUsage {
    limit: number | null;
    current: number;
    pending: number;
    excluded: number;
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

interface BillingDefaults {
    currency: string;
    unit_price: number;
    min_billable_assets: number;
    ai_fair_use_per_asset: number;
    ai_overage_unit_price: number;
    fx_usd_rate: number;
}

interface InvoiceRow {
    id: number;
    periodStart: string | null;
    periodEnd: string | null;
    total: number;
    currency: string;
    status: string;
    hasReceipt: boolean;
    paidAt: string | null;
}

interface AdminTenantShowProps {
    tenant: Tenant;
    subscription: Subscription | null;
    members: Member[];
    features: Feature[];
    usage: UsageRow[];
    invoices: InvoiceRow[];
    plans: PlanOption[];
    assetUsage: AssetUsage;
    billingTerms: BillingTerms;
    billingDefaults: BillingDefaults;
}

const STATUS_LABEL: Record<string, string> = {
    active: 'Activa',
    past_due: 'Morosa',
    suspended: 'Suspendida',
    canceled: 'Cancelada',
    expired: 'Expirada',
};

const OPERATIONAL = ['active', 'past_due'];

function Panel({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="rounded-md border border-border bg-surface-1">
            <h2 className="sam-h3 m-0 border-b border-border px-4 py-2.5">
                {title}
            </h2>
            <div className="p-4">{children}</div>
        </section>
    );
}

export default function AdminTenantShow({
    tenant,
    subscription,
    members,
    features,
    usage,
    invoices,
    plans,
    assetUsage,
    billingTerms,
    billingDefaults,
}: AdminTenantShowProps) {
    const [planCode, setPlanCode] = useState('');
    const [confirm, setConfirm] = useState<{
        title: string;
        description: string;
        run: () => void;
    } | null>(null);

    const base = `/admin/tenants/${tenant.slug}/subscription`;
    const status = subscription?.status ?? null;
    const isOperational = status !== null && OPERATIONAL.includes(status);

    const post = (
        path: string,
        msg: string,
        data: Record<string, string | number> = {},
    ) =>
        router.post(`${base}/${path}`, data, {
            preserveScroll: true,
            onSuccess: () => toast.success(msg),
            onError: () => toast.error('No se pudo completar la acción.'),
        });

    const changePlan = () => {
        if (!planCode) {
            return;
        }

        router.put(
            base,
            { plan_code: planCode },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Plan actualizado.');
                    setPlanCode('');
                },
                onError: () => toast.error('No se pudo cambiar el plan.'),
            },
        );
    };

    const [newMemberEmail, setNewMemberEmail] = useState('');

    const [newMemberName, setNewMemberName] = useState('');
    const [newMemberRole, setNewMemberRole] = useState('member');

    const assetFeature = features.find((f) => f.key === 'monitored_assets');
    const initialAssetLimit = assetFeature?.limits?.included_quantity;
    const [assetLimitInput, setAssetLimitInput] = useState(
        initialAssetLimit != null ? String(initialAssetLimit) : '',
    );

    const [tenantName, setTenantName] = useState(tenant.name);
    const [displayName, setDisplayName] = useState(
        tenant.branding.displayName ?? '',
    );
    const [primaryColor, setPrimaryColor] = useState(
        tenant.branding.primaryColor ?? '',
    );
    const [logoUrl, setLogoUrl] = useState(tenant.branding.logoUrl ?? '');
    const [deleteText, setDeleteText] = useState('');

    const ok = (msg: string) => ({
        preserveScroll: true,
        onSuccess: () => toast.success(msg),
        onError: () => toast.error('No se pudo completar la acción.'),
    });

    const updateFeature = (
        key: string,
        enabled: boolean,
        includedQuantity?: number,
    ) =>
        router.put(
            `/admin/tenants/${tenant.slug}/features/${key}`,
            includedQuantity === undefined
                ? { enabled }
                : { enabled, included_quantity: includedQuantity },
            ok('Feature actualizada.'),
        );

    const memberBase = `/admin/tenants/${tenant.slug}/members`;

    const changeRole = (userId: number, role: string) =>
        router.put(`${memberBase}/${userId}`, { role }, ok('Rol actualizado.'));

    const removeMember = (userId: number) =>
        router.delete(`${memberBase}/${userId}`, ok('Miembro removido.'));

    const makeOwner = (userId: number) =>
        router.post(
            `${memberBase}/${userId}/make-owner`,
            {},
            ok('Propietario reasignado.'),
        );

    const sendAccess = (userId: number) =>
        router.post(
            `${memberBase}/${userId}/send-access`,
            {},
            {
                preserveScroll: true,
                onError: (errors) =>
                    toast.error(
                        Object.values(errors)[0] ??
                            'No se pudo reenviar el acceso.',
                    ),
            },
        );

    const addMember = () => {
        if (!newMemberEmail) {
            return;
        }

        router.post(
            memberBase,
            {
                email: newMemberEmail,
                name: newMemberName || null,
                role: newMemberRole,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setNewMemberEmail('');
                    setNewMemberName('');
                },
                onError: (errors) =>
                    toast.error(
                        Object.values(errors)[0] ?? 'No se pudo añadir.',
                    ),
            },
        );
    };

    const saveTenant = () =>
        router.put(
            `/admin/tenants/${tenant.slug}`,
            {
                name: tenantName,
                display_name: displayName || null,
                primary_color: primaryColor || null,
                logo_url: logoUrl || null,
            },
            ok('Tenant actualizado.'),
        );

    const deleteTenant = () =>
        router.delete(`/admin/tenants/${tenant.slug}`, {
            onError: () => toast.error('No se pudo eliminar el tenant.'),
        });

    const assetLimitLabel =
        assetUsage.limit === null ? 'sin tope' : `${assetUsage.limit}`;
    const overCap =
        assetUsage.limit !== null && assetUsage.current > assetUsage.limit;

    // Términos comerciales: campo vacío = default de plataforma.
    const [terms, setTerms] = useState({
        unit_price: billingTerms.explicit
            ? String(billingTerms.unit_price)
            : '',
        currency: billingTerms.explicit ? billingTerms.currency : '',
        included_assets:
            billingTerms.included_assets !== null
                ? String(billingTerms.included_assets)
                : '',
        min_billable_assets: billingTerms.explicit
            ? String(billingTerms.min_billable_assets)
            : '',
        ai_fair_use_per_asset: billingTerms.explicit
            ? String(billingTerms.ai_fair_use_per_asset)
            : '',
        ai_overage_unit_price: billingTerms.explicit
            ? String(billingTerms.ai_overage_unit_price)
            : '',
        messaging_markup_percent:
            billingTerms.messaging_markup_percent !== null
                ? String(billingTerms.messaging_markup_percent)
                : '',
        fx_usd_rate: billingTerms.explicit
            ? String(billingTerms.fx_usd_rate)
            : '',
    });

    const setTerm = (key: keyof typeof terms) => (value: string) =>
        setTerms((prev) => ({ ...prev, [key]: value }));

    const saveTerms = () => {
        const num = (v: string) => (v.trim() === '' ? null : Number(v));

        router.put(
            `/admin/tenants/${tenant.slug}/billing-terms`,
            {
                unit_price: num(terms.unit_price),
                currency: terms.currency.trim() === '' ? null : terms.currency,
                included_assets: num(terms.included_assets),
                min_billable_assets: num(terms.min_billable_assets),
                ai_fair_use_per_asset: num(terms.ai_fair_use_per_asset),
                ai_overage_unit_price: num(terms.ai_overage_unit_price),
                messaging_markup_percent: num(terms.messaging_markup_percent),
                fx_usd_rate: num(terms.fx_usd_rate),
            },
            ok('Términos de facturación actualizados.'),
        );
    };

    const generateInvoice = () =>
        router.post(
            `/admin/tenants/${tenant.slug}/invoices/generate`,
            {},
            ok('Factura del mes anterior en generación.'),
        );

    return (
        <div className="flex h-full flex-col overflow-hidden">
            <Head title={tenant.name} />

            <header className="flex shrink-0 items-center justify-between gap-3 border-b border-border bg-surface-1 px-5 py-3">
                <div className="flex items-center gap-3">
                    <Link
                        href={adminTenantsIndex().url}
                        className="text-fg-3 hover:text-fg-1"
                        aria-label="Volver a tenants"
                    >
                        <ArrowLeft size={16} />
                    </Link>
                    <h1 className="sam-h2 m-0">{tenant.name}</h1>
                    <span className="sam-meta">{tenant.slug}</span>
                </div>
                {tenant.isPersonal ? null : (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(impersonateStore(tenant.slug).url)
                        }
                    >
                        <UserCog size={13} /> Impersonar
                    </Button>
                )}
            </header>

            <div className="flex-1 overflow-y-auto p-5">
                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel title="Suscripción">
                        {subscription ? (
                            <dl className="grid grid-cols-2 gap-y-2 text-sm">
                                <dt className="sam-meta">Plan</dt>
                                <dd>{subscription.plan ?? '—'}</dd>
                                <dt className="sam-meta">Estado</dt>
                                <dd>
                                    {STATUS_LABEL[subscription.status] ??
                                        subscription.status}
                                </dd>
                                <dt className="sam-meta">Ciclo</dt>
                                <dd>{subscription.billingCycle ?? '—'}</dd>
                                <dt className="sam-meta">Inicio</dt>
                                <dd>{formatDate(subscription.startsAt)}</dd>
                                <dt className="sam-meta">Renueva</dt>
                                <dd>{formatDate(subscription.renewsAt)}</dd>
                            </dl>
                        ) : (
                            <p className="text-sm text-fg-3">
                                Sin suscripción. Asigna un plan para crear una.
                            </p>
                        )}

                        {tenant.isPersonal ? null : (
                            <div className="mt-4 flex flex-col gap-3 border-t border-border pt-4">
                                <div className="flex items-end gap-2">
                                    <div className="flex-1">
                                        <Label
                                            htmlFor="plan-select"
                                            className="sam-meta"
                                        >
                                            Cambiar plan
                                        </Label>
                                        <Select
                                            value={planCode}
                                            onValueChange={setPlanCode}
                                        >
                                            <SelectTrigger id="plan-select">
                                                <SelectValue placeholder="Selecciona plan" />
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
                                        onClick={changePlan}
                                        disabled={!planCode}
                                    >
                                        Aplicar
                                    </Button>
                                </div>

                                <div className="flex flex-wrap items-center gap-2">
                                    {isOperational ? (
                                        <>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setConfirm({
                                                        title: 'Suspender suscripción',
                                                        description: `Esto corta el acceso operativo de ${tenant.name}. Es reversible.`,
                                                        run: () =>
                                                            post(
                                                                'suspend',
                                                                'Suscripción suspendida.',
                                                            ),
                                                    })
                                                }
                                            >
                                                Suspender
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setConfirm({
                                                        title: 'Cancelar suscripción',
                                                        description: `La suscripción de ${tenant.name} quedará cancelada.`,
                                                        run: () =>
                                                            post(
                                                                'cancel',
                                                                'Suscripción cancelada.',
                                                            ),
                                                    })
                                                }
                                            >
                                                Cancelar
                                            </Button>
                                        </>
                                    ) : subscription ? (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                post(
                                                    'reactivate',
                                                    'Suscripción reactivada.',
                                                )
                                            }
                                        >
                                            Reactivar
                                        </Button>
                                    ) : null}
                                </div>
                            </div>
                        )}
                    </Panel>

                    <Panel title="Unidades vigiladas">
                        <div className="flex items-baseline gap-2">
                            <span className="text-2xl font-semibold tabular-nums">
                                {assetUsage.current}
                            </span>
                            <span className="sam-meta">
                                de {assetLimitLabel}
                            </span>
                        </div>
                        <p
                            className={
                                overCap
                                    ? 'mt-1 text-xs text-severity-medium'
                                    : 'mt-1 text-xs text-fg-3'
                            }
                        >
                            {overCap && assetUsage.limit !== null
                                ? `${assetUsage.current - assetUsage.limit} por encima del tope contratado: se cobran como extra por día.`
                                : 'Tope de los términos del tenant, o del override/plan si no hay términos.'}
                        </p>
                        <dl className="mt-3 grid grid-cols-2 gap-y-1 text-sm">
                            <dt className="sam-meta">
                                Sin vigilar (pendientes)
                            </dt>
                            <dd className="tabular-nums">
                                {assetUsage.pending}
                            </dd>
                            <dt className="sam-meta">Excluidas</dt>
                            <dd className="tabular-nums">
                                {assetUsage.excluded}
                            </dd>
                        </dl>
                        <p className="mt-2 text-xs text-fg-3">
                            El cliente decide qué unidades enciende desde su
                            pantalla de Flota; SAM sólo cobra las vigiladas, por
                            día.
                        </p>
                    </Panel>

                    {tenant.isPersonal ? null : (
                        <Panel title="Términos de facturación (por tracto-día)">
                            <p className="mb-3 text-xs text-fg-3">
                                Vacío = default de plataforma (
                                {billingDefaults.unit_price}{' '}
                                {billingDefaults.currency.toUpperCase()} por
                                tracto al mes, mínimo{' '}
                                {billingDefaults.min_billable_assets}, IA{' '}
                                {billingDefaults.ai_fair_use_per_asset}{' '}
                                evaluaciones por tracto, extra a{' '}
                                {billingDefaults.ai_overage_unit_price}, USD→
                                {billingDefaults.currency.toUpperCase()}{' '}
                                {billingDefaults.fx_usd_rate}).
                                {billingTerms.explicit
                                    ? ' Este tenant tiene términos propios.'
                                    : ' Este tenant usa los defaults.'}
                            </p>
                            <div className="grid grid-cols-2 gap-3">
                                {(
                                    [
                                        [
                                            'unit_price',
                                            'Precio por tracto / mes',
                                        ],
                                        ['currency', 'Moneda (ISO)'],
                                        [
                                            'included_assets',
                                            'Tope contratado (tractos)',
                                        ],
                                        [
                                            'min_billable_assets',
                                            'Mínimo facturable (tractos)',
                                        ],
                                        [
                                            'ai_fair_use_per_asset',
                                            'IA incluida por tracto',
                                        ],
                                        [
                                            'ai_overage_unit_price',
                                            'IA extra (por evaluación)',
                                        ],
                                        [
                                            'messaging_markup_percent',
                                            'Margen Twilio (%)',
                                        ],
                                        ['fx_usd_rate', 'Tipo de cambio USD'],
                                    ] as [keyof typeof terms, string][]
                                ).map(([key, label]) => (
                                    <div key={key} className="grid gap-1.5">
                                        <Label
                                            htmlFor={`terms-${key}`}
                                            className="sam-meta"
                                        >
                                            {label}
                                        </Label>
                                        <Input
                                            id={`terms-${key}`}
                                            type={
                                                key === 'currency'
                                                    ? 'text'
                                                    : 'number'
                                            }
                                            min={0}
                                            step={
                                                key === 'unit_price' ||
                                                key ===
                                                    'ai_overage_unit_price' ||
                                                key === 'fx_usd_rate' ||
                                                key ===
                                                    'messaging_markup_percent'
                                                    ? '0.01'
                                                    : '1'
                                            }
                                            maxLength={
                                                key === 'currency'
                                                    ? 3
                                                    : undefined
                                            }
                                            value={terms[key]}
                                            onChange={(e) =>
                                                setTerm(key)(e.target.value)
                                            }
                                            placeholder="default"
                                        />
                                    </div>
                                ))}
                            </div>
                            <div className="mt-3 flex justify-end">
                                <Button size="sm" onClick={saveTerms}>
                                    Guardar términos
                                </Button>
                            </div>
                        </Panel>
                    )}

                    {tenant.isPersonal ? null : (
                        <Panel title="Identidad y marca">
                            <div className="flex flex-col gap-3">
                                <div className="grid gap-1.5">
                                    <Label
                                        htmlFor="tenant-name"
                                        className="sam-meta"
                                    >
                                        Nombre
                                    </Label>
                                    <Input
                                        id="tenant-name"
                                        value={tenantName}
                                        onChange={(e) =>
                                            setTenantName(e.target.value)
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label
                                        htmlFor="display-name"
                                        className="sam-meta"
                                    >
                                        Nombre visible (marca)
                                    </Label>
                                    <Input
                                        id="display-name"
                                        value={displayName}
                                        onChange={(e) =>
                                            setDisplayName(e.target.value)
                                        }
                                    />
                                </div>
                                <div className="flex gap-2">
                                    <div className="grid flex-1 gap-1.5">
                                        <Label
                                            htmlFor="primary-color"
                                            className="sam-meta"
                                        >
                                            Color primario
                                        </Label>
                                        <Input
                                            id="primary-color"
                                            value={primaryColor}
                                            onChange={(e) =>
                                                setPrimaryColor(e.target.value)
                                            }
                                            placeholder="#2563eb"
                                        />
                                    </div>
                                    <div className="grid flex-[2] gap-1.5">
                                        <Label
                                            htmlFor="logo-url"
                                            className="sam-meta"
                                        >
                                            Logo URL
                                        </Label>
                                        <Input
                                            id="logo-url"
                                            value={logoUrl}
                                            onChange={(e) =>
                                                setLogoUrl(e.target.value)
                                            }
                                        />
                                    </div>
                                </div>
                                <div className="flex justify-end">
                                    <Button size="sm" onClick={saveTenant}>
                                        Guardar
                                    </Button>
                                </div>
                            </div>
                        </Panel>
                    )}

                    <Panel title={`Miembros (${members.length})`}>
                        {members.length === 0 ? (
                            <p className="text-sm text-fg-3">Sin miembros.</p>
                        ) : (
                            <ul className="flex flex-col gap-2 text-sm">
                                {members.map((member) => {
                                    const isOwner = member.role === 'owner';

                                    return (
                                        <li
                                            key={member.id}
                                            className="flex items-center justify-between gap-2"
                                        >
                                            <span className="min-w-0 truncate">
                                                {member.name}{' '}
                                                <span className="sam-meta">
                                                    {member.email}
                                                </span>
                                                {member.pendingAccess ? (
                                                    <>
                                                        {' '}
                                                        <span className="sam-meta rounded bg-surface-2 px-1.5 py-0.5 text-severity-medium">
                                                            Acceso pendiente
                                                        </span>{' '}
                                                        <button
                                                            type="button"
                                                            className="sam-meta underline underline-offset-2 hover:text-fg-1"
                                                            onClick={() =>
                                                                sendAccess(
                                                                    member.id,
                                                                )
                                                            }
                                                        >
                                                            Reenviar acceso
                                                        </button>
                                                    </>
                                                ) : null}
                                            </span>
                                            {tenant.isPersonal || isOwner ? (
                                                <span className="sam-meta rounded bg-surface-2 px-1.5 py-0.5">
                                                    {member.role}
                                                </span>
                                            ) : (
                                                <span className="flex shrink-0 items-center gap-1.5">
                                                    <Select
                                                        value={member.role}
                                                        onValueChange={(v) =>
                                                            changeRole(
                                                                member.id,
                                                                v,
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger className="h-7 w-24">
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="admin">
                                                                admin
                                                            </SelectItem>
                                                            <SelectItem value="member">
                                                                member
                                                            </SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            makeOwner(member.id)
                                                        }
                                                    >
                                                        Owner
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setConfirm({
                                                                title: 'Quitar miembro',
                                                                description: `¿Quitar a ${member.email} de ${tenant.name}?`,
                                                                run: () =>
                                                                    removeMember(
                                                                        member.id,
                                                                    ),
                                                            })
                                                        }
                                                    >
                                                        Quitar
                                                    </Button>
                                                </span>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}

                        {tenant.isPersonal ? null : (
                            <div className="mt-4 flex items-end gap-2 border-t border-border pt-4">
                                <div className="flex-1">
                                    <Label
                                        htmlFor="member-email"
                                        className="sam-meta"
                                    >
                                        Añadir miembro
                                    </Label>
                                    <Input
                                        id="member-email"
                                        type="email"
                                        value={newMemberEmail}
                                        onChange={(e) =>
                                            setNewMemberEmail(e.target.value)
                                        }
                                        placeholder="user@empresa.com"
                                    />
                                    <Input
                                        aria-label="Nombre (si no tiene cuenta)"
                                        className="mt-1.5"
                                        value={newMemberName}
                                        onChange={(e) =>
                                            setNewMemberName(e.target.value)
                                        }
                                        placeholder="Nombre (si no tiene cuenta)"
                                    />
                                </div>
                                <Select
                                    value={newMemberRole}
                                    onValueChange={setNewMemberRole}
                                >
                                    <SelectTrigger className="w-24">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="admin">
                                            admin
                                        </SelectItem>
                                        <SelectItem value="member">
                                            member
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <Button
                                    size="sm"
                                    onClick={addMember}
                                    disabled={!newMemberEmail}
                                >
                                    Añadir
                                </Button>
                            </div>
                        )}
                    </Panel>

                    <Panel title={`Features (${features.length})`}>
                        {features.length === 0 ? (
                            <p className="text-sm text-fg-3">Sin features.</p>
                        ) : (
                            <ul className="flex flex-col gap-2 text-sm">
                                {features.map((feature) => (
                                    <li
                                        key={feature.key}
                                        className="flex items-center justify-between gap-2"
                                    >
                                        <span className="flex items-center gap-2">
                                            <Checkbox
                                                checked={feature.enabled}
                                                disabled={tenant.isPersonal}
                                                onCheckedChange={(c) =>
                                                    updateFeature(
                                                        feature.key,
                                                        c === true,
                                                    )
                                                }
                                                aria-label={`Activar ${feature.key}`}
                                            />
                                            <span className="font-mono text-xs">
                                                {feature.key}
                                            </span>
                                        </span>
                                        <span className="sam-meta">
                                            {feature.source}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {!tenant.isPersonal && assetFeature ? (
                            <div className="mt-4 flex items-end gap-2 border-t border-border pt-4">
                                <div className="flex-1">
                                    <Label
                                        htmlFor="asset-limit"
                                        className="sam-meta"
                                    >
                                        Tope de activos (override)
                                    </Label>
                                    <Input
                                        id="asset-limit"
                                        type="number"
                                        min={0}
                                        value={assetLimitInput}
                                        onChange={(e) =>
                                            setAssetLimitInput(e.target.value)
                                        }
                                        placeholder="sin tope"
                                    />
                                </div>
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        updateFeature(
                                            'monitored_assets',
                                            assetFeature.enabled,
                                            Math.max(
                                                0,
                                                Number(assetLimitInput) || 0,
                                            ),
                                        )
                                    }
                                >
                                    Guardar tope
                                </Button>
                            </div>
                        ) : null}
                    </Panel>

                    <Panel title="Facturas (transferencia)">
                        {tenant.isPersonal ? null : (
                            <div className="mb-3 flex items-center justify-between gap-2 border-b border-border pb-3">
                                <p className="text-xs text-fg-3">
                                    El día 1 se cierra el mes anterior de forma
                                    automática. Aquí puedes generarlo a demanda.
                                </p>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setConfirm({
                                            title: 'Generar factura del mes anterior',
                                            description: `Se recalculan los contadores y se emite la factura de ${tenant.name}. Si ya existe, no se duplica.`,
                                            run: generateInvoice,
                                        })
                                    }
                                >
                                    Generar factura
                                </Button>
                            </div>
                        )}
                        {invoices.length === 0 ? (
                            <p className="text-sm text-fg-3">Sin facturas.</p>
                        ) : (
                            <ul className="flex flex-col gap-2 text-sm">
                                {invoices.map((invoice) => (
                                    <li
                                        key={invoice.id}
                                        className="flex flex-wrap items-center gap-2"
                                    >
                                        <span className="font-mono text-xs text-fg-3">
                                            #{invoice.id}
                                        </span>
                                        <span>
                                            {formatDate(invoice.periodStart)} —{' '}
                                            {formatDate(invoice.periodEnd)}
                                        </span>
                                        <span className="font-semibold">
                                            {formatCurrency(
                                                invoice.total,
                                                invoice.currency,
                                            )}
                                        </span>
                                        <span className="text-xs text-fg-3">
                                            {invoiceStatusLabel(invoice.status)}
                                            {invoice.hasReceipt &&
                                                ' · comprobante recibido'}
                                            {invoice.paidAt &&
                                                ` · pagada el ${formatDate(invoice.paidAt)}`}
                                        </span>
                                        {/* Pagada o anulada: ciclo cerrado,
                                            el servidor rechaza cualquier
                                            transición posterior. */}
                                        {invoice.status !== 'paid' &&
                                            invoice.status !== 'void' && (
                                                <span className="ml-auto flex gap-1">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.post(
                                                                `/admin/tenants/${tenant.slug}/invoices/${invoice.id}/mark-paid`,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                    onSuccess:
                                                                        () =>
                                                                            toast.success(
                                                                                'Factura marcada como pagada.',
                                                                            ),
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Marcar pagada
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            router.post(
                                                                `/admin/tenants/${tenant.slug}/invoices/${invoice.id}/void`,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                    onSuccess:
                                                                        () =>
                                                                            toast.success(
                                                                                'Factura anulada.',
                                                                            ),
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Anular
                                                    </Button>
                                                </span>
                                            )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel title="Uso (periodo actual)">
                        {usage.length === 0 ? (
                            <p className="text-sm text-fg-3">
                                Sin datos de uso.
                            </p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead className="text-left">
                                    <tr className="sam-meta">
                                        <th className="py-1 font-medium">
                                            Medidor
                                        </th>
                                        <th className="py-1 font-medium">
                                            Consumido
                                        </th>
                                        <th className="py-1 font-medium">
                                            Incluido
                                        </th>
                                        <th className="py-1 font-medium">
                                            Excedente
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {usage.map((row, i) => (
                                        <tr
                                            key={`${row.meter}-${i}`}
                                            className="border-t border-border"
                                        >
                                            <td className="py-1">
                                                {row.meter}
                                            </td>
                                            {row.money ? (
                                                <td
                                                    className="py-1 tabular-nums"
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
                                                    <td className="py-1 tabular-nums">
                                                        {row.consumed}
                                                    </td>
                                                    <td className="py-1 tabular-nums">
                                                        {row.included}
                                                    </td>
                                                    <td className="py-1 tabular-nums">
                                                        {row.overage}
                                                    </td>
                                                </>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </Panel>
                </div>

                {tenant.isPersonal ? null : (
                    <div className="mt-4 rounded-md border border-health-down/40 bg-surface-1">
                        <h2 className="sam-h3 m-0 border-b border-health-down/30 px-4 py-2.5 text-health-down">
                            Zona de peligro
                        </h2>
                        <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-end">
                            <div className="flex-1">
                                <Label
                                    htmlFor="delete-confirm"
                                    className="sam-meta"
                                >
                                    Eliminar tenant (soft-delete). Escribe{' '}
                                    <span className="font-mono">
                                        {tenant.slug}
                                    </span>{' '}
                                    para confirmar.
                                </Label>
                                <Input
                                    id="delete-confirm"
                                    value={deleteText}
                                    onChange={(e) =>
                                        setDeleteText(e.target.value)
                                    }
                                    placeholder={tenant.slug}
                                />
                            </div>
                            <Button
                                variant="destructive"
                                onClick={() =>
                                    setConfirm({
                                        title: 'Eliminar tenant',
                                        description: `El tenant ${tenant.name} quedará eliminado (soft-delete).`,
                                        run: deleteTenant,
                                    })
                                }
                                disabled={deleteText !== tenant.slug}
                            >
                                Eliminar tenant
                            </Button>
                        </div>
                    </div>
                )}
            </div>

            <Dialog
                open={confirm !== null}
                onOpenChange={(open) => !open && setConfirm(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{confirm?.title}</DialogTitle>
                        <DialogDescription>
                            {confirm?.description}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setConfirm(null)}
                        >
                            Cancelar
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                confirm?.run();
                                setConfirm(null);
                            }}
                        >
                            Confirmar
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
