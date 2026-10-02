import { Head, router } from '@inertiajs/react';
import {
    CheckCircle2,
    Circle,
    Gauge,
    Mail,
    UserCog,
    UserPlus,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import {
    AdminSection,
    AdminTenantBilling,
    CapMeterBar,
} from '@/components/sam/admin-tenant-billing';
import type {
    AssetUsage,
    BillingDefaults,
    BillingTerms,
    InvoiceRow,
    PlanOption,
    SubscriptionInfo,
} from '@/components/sam/admin-tenant-billing';
import { SubscriptionPill } from '@/components/sam/admin-tenant-status';
import { BillingPill } from '@/components/sam/billing/panel';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import { EntityAvatar } from '@/components/sam/entity-avatar';
import { MetaChip } from '@/components/sam/meta-chip';
import { TabBar } from '@/components/sam/tab-bar';
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
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { formatCurrency, formatDate } from '@/lib/format';
import { humanizeCode, meterLabel, teamRoleLabel } from '@/lib/labels';
import { timezoneLabel, TENANT_TIMEZONES } from '@/lib/timezones';
import { cn } from '@/lib/utils';
import { store as impersonateStore } from '@/routes/admin/impersonate';
import {
    destroy as destroyTenant,
    index as adminTenantsIndex,
    show as adminTenantShow,
    update as updateTenant,
} from '@/routes/admin/tenants';
import { update as updateFeature } from '@/routes/admin/tenants/features';
import {
    destroy as removeMemberRoute,
    makeOwner as makeOwnerRoute,
    sendAccess as sendAccessRoute,
    store as addMemberRoute,
    update as updateMemberRoute,
} from '@/routes/admin/tenants/members';

interface Tenant {
    id: number;
    name: string;
    slug: string;
    isPersonal: boolean;
    timezone: string | null;
    createdAt: string | null;
    branding: {
        displayName: string | null;
        primaryColor: string | null;
        secondaryColor: string | null;
        logoUrl: string | null;
    };
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
    meterCode: string | null;
    periodStart: string | null;
    consumed: number;
    included: number;
    overage: number;
    money: { providerCost: number; charged: number } | null;
}

interface SetupStep {
    key: string;
    label: string;
    done: boolean;
    detail: string;
}

interface Setup {
    steps: SetupStep[];
    completed: number;
    total: number;
}

interface AdminTenantShowProps {
    tenant: Tenant;
    subscription: SubscriptionInfo | null;
    members: Member[];
    setup: Setup;
    features: Feature[];
    usage: UsageRow[];
    invoices: InvoiceRow[];
    plans: PlanOption[];
    assetUsage: AssetUsage;
    billingTerms: BillingTerms;
    billingDefaults: BillingDefaults;
}

type TabKey =
    | 'summary'
    | 'billing'
    | 'members'
    | 'features'
    | 'usage'
    | 'settings';

const TAB_KEYS: TabKey[] = [
    'summary',
    'billing',
    'members',
    'features',
    'usage',
    'settings',
];

const SOURCE_LABELS: Record<string, string> = {
    default_plan: 'Del plan',
    manual_override: 'Manual',
    promo: 'Promoción',
    beta_access: 'Beta',
};

interface Pending {
    title: string;
    description: string;
    confirmLabel: string;
    tone: 'destructive' | 'default';
    run: () => Promise<void>;
}

function visit(
    method: 'post' | 'put' | 'delete',
    url: string,
    data: Record<string, unknown> = {},
    onError?: (errors: Record<string, string>) => void,
): Promise<void> {
    return new Promise((resolve) => {
        const options = {
            preserveScroll: true,
            preserveState: true,
            onError,
            onFinish: () => resolve(),
        };

        if (method === 'delete') {
            router.delete(url, options);
        } else {
            router[method](url, data as never, options);
        }
    });
}

function initialTab(): TabKey {
    if (typeof window === 'undefined') {
        return 'summary';
    }

    const tab = new URLSearchParams(window.location.search).get('tab');

    return TAB_KEYS.includes(tab as TabKey) ? (tab as TabKey) : 'summary';
}

function syncTabToUrl(key: TabKey): void {
    const url = new URL(window.location.href);

    if (key === 'summary') {
        url.searchParams.delete('tab');
    } else {
        url.searchParams.set('tab', key);
    }

    if (url.href !== window.location.href) {
        window.history.replaceState(window.history.state, '', url);
    }
}

function SetupChecklist({
    setup,
    onGo,
}: {
    setup: Setup;
    onGo: (tab: TabKey) => void;
}) {
    const ready = setup.completed === setup.total;

    return (
        <AdminSection
            title={ready ? 'Listo para operar' : 'Puesta en marcha'}
            description={
                ready
                    ? 'Todo lo necesario para que su monitoreo funcione está en su lugar.'
                    : 'Lo que falta para que este cliente opere de punta a punta.'
            }
            actions={
                <span className="flex items-center gap-2 text-xs text-fg-3 tabular-nums">
                    <span
                        className="h-1.5 w-20 overflow-hidden rounded-full bg-surface-3"
                        aria-hidden="true"
                    >
                        <span
                            className={cn(
                                'block h-full rounded-full',
                                ready ? 'bg-health-ok' : 'bg-primary',
                            )}
                            style={{
                                width: `${(setup.completed / Math.max(1, setup.total)) * 100}%`,
                            }}
                        />
                    </span>
                    {setup.completed} de {setup.total}
                </span>
            }
        >
            <ol className="grid gap-3 sm:grid-cols-2">
                {setup.steps.map((step) => (
                    <li key={step.key} className="flex gap-2.5">
                        {step.done ? (
                            <CheckCircle2
                                className="mt-0.5 size-4 shrink-0 text-health-ok"
                                aria-label="Hecho"
                            />
                        ) : (
                            <Circle
                                className="mt-0.5 size-4 shrink-0 text-fg-3"
                                aria-label="Pendiente"
                            />
                        )}
                        <div className="min-w-0">
                            <p
                                className={cn(
                                    'text-sm font-medium',
                                    step.done ? 'text-fg-2' : 'text-fg-1',
                                )}
                            >
                                {step.label}
                            </p>
                            <p className="text-xs text-fg-3">
                                {step.key === 'timezone' && step.done
                                    ? timezoneLabel(step.detail)
                                    : step.detail}
                            </p>
                            {!step.done && step.key === 'owner' ? (
                                <button
                                    type="button"
                                    className="mt-1 text-xs font-medium text-primary hover:underline"
                                    onClick={() => onGo('members')}
                                >
                                    Ir a miembros
                                </button>
                            ) : null}
                            {!step.done && step.key === 'timezone' ? (
                                <button
                                    type="button"
                                    className="mt-1 text-xs font-medium text-primary hover:underline"
                                    onClick={() => onGo('settings')}
                                >
                                    Definir zona horaria
                                </button>
                            ) : null}
                        </div>
                    </li>
                ))}
            </ol>
        </AdminSection>
    );
}

export default function AdminTenantShow({
    tenant,
    subscription,
    members,
    setup,
    features,
    usage,
    invoices,
    plans,
    assetUsage,
    billingTerms,
    billingDefaults,
}: AdminTenantShowProps) {
    const [tab, setTabState] = useState<TabKey>(initialTab);
    const [pending, setPending] = useState<Pending | null>(null);
    const [entering, setEntering] = useState(false);

    // Las acciones redirigen a la URL limpia del cliente: se conserva el
    // estado (preserveState) y aquí se re-escribe `?tab=` para que recargar
    // o compartir el enlace abra la misma pestaña.
    useEffect(() => {
        return router.on('navigate', () => syncTabToUrl(tab));
    }, [tab]);

    const setTab = (next: string) => {
        const key = next as TabKey;
        setTabState(key);
        syncTabToUrl(key);
    };

    const owner = members.find((m) => m.role === 'owner') ?? null;
    const assetFeature = features.find((f) => f.key === 'monitored_assets');
    const assetLimit = assetFeature?.limits?.included_quantity;

    const impersonate = () =>
        router.post(
            impersonateStore(tenant.slug).url,
            {},
            {
                onStart: () => setEntering(true),
                onFinish: () => setEntering(false),
            },
        );

    return (
        <>
            <Head title={tenant.name} />
            <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
                <header className="shrink-0 border-b border-border bg-surface-1 px-5 pt-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="flex min-w-0 items-center gap-3">
                            <EntityAvatar
                                name={tenant.name}
                                shape="square"
                                size={36}
                            />
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h1 className="sam-h2 min-w-0 truncate">
                                        {tenant.name}
                                    </h1>
                                    <SubscriptionPill
                                        status={subscription?.status ?? null}
                                    />
                                    {setup.completed === setup.total ? (
                                        <BillingPill tone="ok">
                                            Operando
                                        </BillingPill>
                                    ) : (
                                        <BillingPill tone="warn">
                                            En alta · {setup.completed}/
                                            {setup.total}
                                        </BillingPill>
                                    )}
                                </div>
                                <p className="mt-0.5 truncate text-xs text-fg-3">
                                    <span className="font-mono">
                                        {tenant.slug}
                                    </span>
                                    {' · '}Alta {formatDate(tenant.createdAt)}
                                    {' · '}
                                    {timezoneLabel(tenant.timezone)}
                                </p>
                            </div>
                        </div>
                        {tenant.isPersonal ? null : (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={impersonate}
                                disabled={entering}
                            >
                                {entering ? (
                                    <Spinner />
                                ) : (
                                    <UserCog className="size-3.5" />
                                )}
                                Entrar a su consola
                            </Button>
                        )}
                    </div>
                    <TabBar
                        className="mt-3 -mb-px"
                        aria-label="Secciones del cliente"
                        value={tab}
                        onChange={setTab}
                        items={[
                            { key: 'summary', label: 'Resumen' },
                            { key: 'billing', label: 'Cobro' },
                            {
                                key: 'members',
                                label: 'Miembros',
                                count: members.length,
                            },
                            {
                                key: 'features',
                                label: 'Funciones',
                                count: features.length,
                            },
                            { key: 'usage', label: 'Consumo' },
                            { key: 'settings', label: 'Ajustes' },
                        ]}
                    />
                </header>

                <div className="min-h-0 flex-1 overflow-y-auto p-5">
                    {tab === 'summary' ? (
                        <div className="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                            <SetupChecklist setup={setup} onGo={setTab} />
                            <div className="grid content-start gap-4">
                                <AdminSection title="Responsable">
                                    {owner ? (
                                        <div className="flex items-center gap-2.5">
                                            <EntityAvatar name={owner.name} />
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium">
                                                    {owner.name}
                                                </p>
                                                <p className="truncate text-xs text-fg-3">
                                                    {owner.email}
                                                </p>
                                            </div>
                                            {owner.pendingAccess ? (
                                                <BillingPill
                                                    tone="warn"
                                                    className="ml-auto"
                                                >
                                                    Acceso pendiente
                                                </BillingPill>
                                            ) : null}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-fg-3">
                                            Sin responsable asignado.
                                        </p>
                                    )}
                                </AdminSection>
                                <AdminSection
                                    title="Unidades vigiladas"
                                    actions={
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => setTab('billing')}
                                        >
                                            Ver cobro
                                        </Button>
                                    }
                                >
                                    <CapMeterBar usage={assetUsage} />
                                </AdminSection>
                            </div>
                        </div>
                    ) : null}

                    {tab === 'billing' ? (
                        <AdminTenantBilling
                            slug={tenant.slug}
                            name={tenant.name}
                            subscription={subscription}
                            plans={plans}
                            assetUsage={assetUsage}
                            assetFeature={
                                assetFeature
                                    ? {
                                          enabled: assetFeature.enabled,
                                          limit:
                                              typeof assetLimit === 'number'
                                                  ? assetLimit
                                                  : null,
                                      }
                                    : null
                            }
                            billingTerms={billingTerms}
                            billingDefaults={billingDefaults}
                            invoices={invoices}
                        />
                    ) : null}

                    {tab === 'members' ? (
                        <MembersTab
                            tenant={tenant}
                            members={members}
                            confirm={setPending}
                        />
                    ) : null}

                    {tab === 'features' ? (
                        <FeaturesTab
                            tenant={tenant}
                            features={features}
                            confirm={setPending}
                        />
                    ) : null}

                    {tab === 'usage' ? <UsageTab usage={usage} /> : null}

                    {tab === 'settings' ? (
                        <SettingsTab tenant={tenant} confirm={setPending} />
                    ) : null}
                </div>
            </div>

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
        </>
    );
}

function MembersTab({
    tenant,
    members,
    confirm,
}: {
    tenant: Tenant;
    members: Member[];
    confirm: (p: Pending) => void;
}) {
    const [email, setEmail] = useState('');
    const [name, setName] = useState('');
    const [role, setRole] = useState('member');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [adding, setAdding] = useState(false);
    const [busy, setBusy] = useState<number | null>(null);

    const add = (e: FormEvent) => {
        e.preventDefault();
        router.post(
            addMemberRoute(tenant.slug).url,
            { email, name: name || null, role },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setAdding(true),
                onFinish: () => setAdding(false),
                onSuccess: () => {
                    setEmail('');
                    setName('');
                    setErrors({});
                },
                onError: setErrors,
            },
        );
    };

    const run =
        (id: number, fn: () => Promise<void>) => async (): Promise<void> => {
            setBusy(id);

            try {
                await fn();
            } finally {
                setBusy(null);
            }
        };

    const sendAccess = (member: Member) =>
        run(member.id, () =>
            visit(
                'post',
                sendAccessRoute({ team: tenant.slug, user: member.id }).url,
                {},
                (errors) =>
                    toast.error(
                        Object.values(errors)[0] ??
                            'No se pudo reenviar el acceso.',
                    ),
            ),
        )();

    return (
        <div className="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <AdminSection
                title="Miembros"
                description="Quién entra a la consola de este cliente."
            >
                {members.length === 0 ? (
                    <EmptyState
                        icon={UserPlus}
                        title="Sin miembros"
                        description="Añade al responsable y a su equipo de monitoreo."
                        className="py-6"
                    />
                ) : (
                    <ul className="divide-y divide-border">
                        {members.map((member) => {
                            const isOwner = member.role === 'owner';

                            return (
                                <li
                                    key={member.id}
                                    className="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5"
                                >
                                    <EntityAvatar name={member.name} />
                                    <div className="min-w-0 flex-1">
                                        <p className="flex flex-wrap items-center gap-1.5 text-sm font-medium">
                                            <span className="truncate">
                                                {member.name}
                                            </span>
                                            {member.pendingAccess ? (
                                                <BillingPill tone="warn">
                                                    Acceso pendiente
                                                </BillingPill>
                                            ) : null}
                                        </p>
                                        <p className="truncate text-xs text-fg-3">
                                            {member.email}
                                        </p>
                                    </div>

                                    {member.pendingAccess ? (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            disabled={busy === member.id}
                                            onClick={() => sendAccess(member)}
                                        >
                                            {busy === member.id ? (
                                                <Spinner />
                                            ) : (
                                                <Mail className="size-3.5" />
                                            )}
                                            Reenviar acceso
                                        </Button>
                                    ) : null}

                                    {tenant.isPersonal || isOwner ? (
                                        <MetaChip>
                                            {teamRoleLabel(member.role)}
                                        </MetaChip>
                                    ) : (
                                        <span className="flex items-center gap-1">
                                            <Select
                                                value={member.role}
                                                onValueChange={(next) =>
                                                    confirm({
                                                        title: `Cambiar el rol de ${member.name}`,
                                                        description: `Pasará a ${teamRoleLabel(next)} en ${tenant.name}.`,
                                                        confirmLabel:
                                                            'Cambiar rol',
                                                        tone: 'default',
                                                        run: () =>
                                                            visit(
                                                                'put',
                                                                updateMemberRoute(
                                                                    {
                                                                        team: tenant.slug,
                                                                        user: member.id,
                                                                    },
                                                                ).url,
                                                                { role: next },
                                                            ),
                                                    })
                                                }
                                            >
                                                <SelectTrigger
                                                    className="h-7 w-36"
                                                    aria-label={`Rol de ${member.name}`}
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="admin">
                                                        Administrador
                                                    </SelectItem>
                                                    <SelectItem value="member">
                                                        Miembro
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    confirm({
                                                        title: `Hacer propietario a ${member.name}`,
                                                        description:
                                                            'El propietario actual pasa a Administrador. El propietario controla facturación y miembros.',
                                                        confirmLabel:
                                                            'Hacer propietario',
                                                        tone: 'default',
                                                        run: () =>
                                                            visit(
                                                                'post',
                                                                makeOwnerRoute({
                                                                    team: tenant.slug,
                                                                    user: member.id,
                                                                }).url,
                                                            ),
                                                    })
                                                }
                                            >
                                                Hacer propietario
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                className="text-destructive"
                                                onClick={() =>
                                                    confirm({
                                                        title: `Quitar a ${member.name}`,
                                                        description: `${member.email} deja de tener acceso a ${tenant.name}.`,
                                                        confirmLabel: 'Quitar',
                                                        tone: 'destructive',
                                                        run: () =>
                                                            visit(
                                                                'delete',
                                                                removeMemberRoute(
                                                                    {
                                                                        team: tenant.slug,
                                                                        user: member.id,
                                                                    },
                                                                ).url,
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
            </AdminSection>

            {tenant.isPersonal ? null : (
                <AdminSection
                    title="Añadir miembro"
                    description="Si el correo no tiene cuenta, se crea y recibe su enlace de acceso (7 días)."
                    className="self-start"
                >
                    <form onSubmit={add} className="grid gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="member-email">Correo</Label>
                            <Input
                                id="member-email"
                                type="email"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                placeholder="monitor@empresa.mx"
                                required
                            />
                            <InputError message={errors.email} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="member-name">Nombre</Label>
                            <Input
                                id="member-name"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder="Obligatorio si no tiene cuenta"
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="member-role">Rol</Label>
                            <Select value={role} onValueChange={setRole}>
                                <SelectTrigger
                                    id="member-role"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="admin">
                                        Administrador
                                    </SelectItem>
                                    <SelectItem value="member">
                                        Miembro
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={errors.role} />
                        </div>
                        <Button
                            type="submit"
                            size="sm"
                            disabled={adding || email === ''}
                        >
                            {adding && <Spinner />}
                            Añadir miembro
                        </Button>
                    </form>
                </AdminSection>
            )}
        </div>
    );
}

function FeaturesTab({
    tenant,
    features,
    confirm,
}: {
    tenant: Tenant;
    features: Feature[];
    confirm: (p: Pending) => void;
}) {
    if (features.length === 0) {
        return (
            <EmptyState
                icon={Gauge}
                title="Sin funciones asignadas"
                description="Las funciones y topes llegan del plan. Asigna un plan en Cobro para sembrarlas."
            />
        );
    }

    return (
        <AdminSection
            title="Funciones y topes"
            description="Lo que este cliente tiene encendido. Apagar una función la bloquea para todo su equipo."
        >
            <ul className="divide-y divide-border">
                {features.map((feature) => {
                    const label = meterLabel(
                        feature.key,
                        humanizeCode(feature.key),
                    );
                    const included = feature.limits?.included_quantity;

                    return (
                        <li
                            key={feature.key}
                            className="flex items-center gap-3 py-2.5"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-medium">{label}</p>
                                <p className="text-xs text-fg-3">
                                    {SOURCE_LABELS[feature.source] ??
                                        humanizeCode(feature.source)}
                                    {typeof included === 'number'
                                        ? ` · ${included} incluidos`
                                        : ''}
                                </p>
                            </div>
                            <Switch
                                checked={feature.enabled}
                                disabled={tenant.isPersonal}
                                aria-label={`${feature.enabled ? 'Apagar' : 'Encender'} ${label}`}
                                onCheckedChange={(next) =>
                                    confirm({
                                        title: `${next ? 'Encender' : 'Apagar'} ${label}`,
                                        description: next
                                            ? `${tenant.name} podrá usar esta función de inmediato.`
                                            : `${tenant.name} deja de poder usar esta función de inmediato.`,
                                        confirmLabel: next
                                            ? 'Encender'
                                            : 'Apagar',
                                        tone: next ? 'default' : 'destructive',
                                        run: () =>
                                            visit(
                                                'put',
                                                updateFeature({
                                                    team: tenant.slug,
                                                    featureKey: feature.key,
                                                }).url,
                                                {
                                                    enabled: next,
                                                    included_quantity:
                                                        typeof included ===
                                                        'number'
                                                            ? included
                                                            : null,
                                                },
                                            ),
                                    })
                                }
                            />
                        </li>
                    );
                })}
            </ul>
        </AdminSection>
    );
}

function UsageTab({ usage }: { usage: UsageRow[] }) {
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
        <AdminSection
            title="Consumo por periodo"
            description="Últimos 20 contadores, del periodo más reciente al más antiguo."
        >
            <div className="overflow-x-auto">
                <table className="w-full min-w-[560px] text-sm">
                    <thead>
                        <tr className="border-b border-border text-left text-3xs font-semibold tracking-caps text-fg-3 uppercase">
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
        </AdminSection>
    );
}

function SettingsTab({
    tenant,
    confirm,
}: {
    tenant: Tenant;
    confirm: (p: Pending) => void;
}) {
    const [form, setForm] = useState({
        name: tenant.name,
        display_name: tenant.branding.displayName ?? '',
        primary_color: tenant.branding.primaryColor ?? '',
        logo_url: tenant.branding.logoUrl ?? '',
        timezone: tenant.timezone ?? '',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [deleteText, setDeleteText] = useState('');
    const [brokenLogo, setBrokenLogo] = useState<string | null>(null);
    const logoBroken = brokenLogo !== null && brokenLogo === form.logo_url;

    const set = (key: keyof typeof form) => (value: string) =>
        setForm((prev) => ({ ...prev, [key]: value }));

    const save = (e: FormEvent) => {
        e.preventDefault();
        router.put(
            updateTenant(tenant.slug).url,
            {
                name: form.name,
                display_name: form.display_name || null,
                primary_color: form.primary_color || null,
                logo_url: form.logo_url || null,
                timezone: form.timezone || null,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => setErrors({}),
                onError: setErrors,
            },
        );
    };

    const colorValid = /^#[0-9a-fA-F]{6}$/.test(form.primary_color);

    return (
        <div className="grid max-w-3xl gap-4">
            <AdminSection
                title="Identidad"
                description="Cómo se llama y se ve este cliente dentro de SAM."
            >
                <form onSubmit={save} className="grid gap-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="tenant-name">Nombre</Label>
                            <Input
                                id="tenant-name"
                                value={form.name}
                                onChange={(e) => set('name')(e.target.value)}
                                required
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="display-name">
                                Nombre visible (marca)
                            </Label>
                            <Input
                                id="display-name"
                                value={form.display_name}
                                onChange={(e) =>
                                    set('display_name')(e.target.value)
                                }
                                placeholder={form.name}
                            />
                            <InputError message={errors.display_name} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="tenant-tz">Zona horaria</Label>
                            <Select
                                value={form.timezone}
                                onValueChange={set('timezone')}
                            >
                                <SelectTrigger
                                    id="tenant-tz"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Sin definir (UTC)" />
                                </SelectTrigger>
                                <SelectContent>
                                    {TENANT_TIMEZONES.map((tz) => (
                                        <SelectItem
                                            key={tz.value}
                                            value={tz.value}
                                        >
                                            {tz.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.timezone} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="primary-color">
                                Color principal
                            </Label>
                            <div className="flex items-center gap-2">
                                <input
                                    type="color"
                                    aria-label="Elegir color principal"
                                    value={
                                        colorValid
                                            ? form.primary_color
                                            : '#2563eb'
                                    }
                                    onChange={(e) =>
                                        set('primary_color')(e.target.value)
                                    }
                                    className="size-9 shrink-0 cursor-pointer rounded-md border border-border bg-transparent p-0.5"
                                />
                                <Input
                                    id="primary-color"
                                    value={form.primary_color}
                                    onChange={(e) =>
                                        set('primary_color')(e.target.value)
                                    }
                                    placeholder="#2563eb"
                                    className="font-mono"
                                    maxLength={7}
                                />
                            </div>
                            <InputError message={errors.primary_color} />
                        </div>
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label htmlFor="logo-url">URL del logo</Label>
                            <div className="flex items-center gap-3">
                                <div className="grid size-12 shrink-0 place-items-center overflow-hidden rounded-md border border-border bg-surface-2">
                                    {form.logo_url && !logoBroken ? (
                                        <img
                                            src={form.logo_url}
                                            alt="Vista previa del logo"
                                            className="size-full object-contain"
                                            onError={() =>
                                                setBrokenLogo(form.logo_url)
                                            }
                                        />
                                    ) : (
                                        <EntityAvatar
                                            name={form.name}
                                            shape="square"
                                            size={32}
                                        />
                                    )}
                                </div>
                                <Input
                                    id="logo-url"
                                    value={form.logo_url}
                                    onChange={(e) =>
                                        set('logo_url')(e.target.value)
                                    }
                                    placeholder="https://…/logo.svg"
                                />
                            </div>
                            {logoBroken ? (
                                <p className="text-xs text-severity-medium">
                                    No se pudo cargar la imagen de esa URL.
                                </p>
                            ) : null}
                            <InputError message={errors.logo_url} />
                        </div>
                    </div>
                    <div className="flex justify-end">
                        <Button type="submit" size="sm" disabled={saving}>
                            {saving && <Spinner />}
                            Guardar cambios
                        </Button>
                    </div>
                </form>
            </AdminSection>

            {tenant.isPersonal ? null : (
                <section className="rounded-lg border border-destructive/30 bg-destructive/5">
                    <header className="border-b border-destructive/20 px-4 py-3">
                        <h2 className="sam-h3 text-destructive">
                            Eliminar cliente
                        </h2>
                        <p className="mt-0.5 text-xs text-fg-3">
                            Su equipo pierde el acceso y sus integraciones dejan
                            de ingerir. Los datos se conservan (baja lógica).
                        </p>
                    </header>
                    <form
                        className="flex flex-col gap-3 p-4 sm:flex-row sm:items-end"
                        onSubmit={(e) => {
                            e.preventDefault();
                            confirm({
                                title: `Eliminar a ${tenant.name}`,
                                description:
                                    'El cliente queda dado de baja y desaparece de la lista. Esta acción no se deshace desde la consola.',
                                confirmLabel: 'Eliminar cliente',
                                tone: 'destructive',
                                run: () =>
                                    visit(
                                        'delete',
                                        destroyTenant(tenant.slug).url,
                                    ),
                            });
                        }}
                    >
                        <div className="grid flex-1 gap-1.5">
                            <Label htmlFor="delete-confirm">
                                Escribe{' '}
                                <span className="font-mono">{tenant.slug}</span>{' '}
                                para confirmar
                            </Label>
                            <Input
                                id="delete-confirm"
                                value={deleteText}
                                onChange={(e) => setDeleteText(e.target.value)}
                                placeholder={tenant.slug}
                                autoComplete="off"
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={deleteText !== tenant.slug}
                        >
                            Eliminar cliente
                        </Button>
                    </form>
                </section>
            )}
        </div>
    );
}

AdminTenantShow.layout = (props: {
    tenant?: { name: string; slug: string };
}) => ({
    breadcrumbs: [
        { title: 'Clientes', href: adminTenantsIndex().url },
        {
            title: props.tenant?.name ?? 'Cliente',
            href: props.tenant
                ? adminTenantShow(props.tenant.slug).url
                : adminTenantsIndex().url,
        },
    ],
});
