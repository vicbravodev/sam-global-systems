import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, UserCog } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
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
import { store as impersonateStore } from '@/routes/admin/impersonate';
import { index as adminTenantsIndex } from '@/routes/admin/tenants';

interface Tenant {
    id: number;
    name: string;
    slug: string;
    isPersonal: boolean;
    createdAt: string | null;
}

interface Subscription {
    status: string;
    plan: string | null;
    billingCycle: string | null;
    startsAt: string | null;
    trialEndsAt: string | null;
    renewsAt: string | null;
}

interface Member {
    id: number;
    name: string;
    email: string;
    role: string;
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
}

interface PlanOption {
    code: string;
    name: string;
}

interface AssetUsage {
    limit: number | null;
    current: number;
}

interface AdminTenantShowProps {
    tenant: Tenant;
    subscription: Subscription | null;
    members: Member[];
    features: Feature[];
    usage: UsageRow[];
    plans: PlanOption[];
    assetUsage: AssetUsage;
}

const STATUS_LABEL: Record<string, string> = {
    active: 'Activa',
    trialing: 'Trial',
    past_due: 'Morosa',
    suspended: 'Suspendida',
    canceled: 'Cancelada',
    expired: 'Expirada',
};

const OPERATIONAL = ['active', 'trialing', 'past_due'];

function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime())
        ? '—'
        : date.toLocaleDateString('es', {
              day: '2-digit',
              month: '2-digit',
              year: 'numeric',
          });
}

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
    plans,
    assetUsage,
}: AdminTenantShowProps) {
    const [planCode, setPlanCode] = useState('');
    const [trialDays, setTrialDays] = useState('14');
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

    const assetLimitLabel =
        assetUsage.limit === null ? 'sin tope' : `${assetUsage.limit}`;
    const overCap =
        assetUsage.limit !== null && assetUsage.current >= assetUsage.limit;

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
                                <dt className="sam-meta">Fin de trial</dt>
                                <dd>{formatDate(subscription.trialEndsAt)}</dd>
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

                                    {subscription ? (
                                        <div className="flex items-center gap-1.5">
                                            <Input
                                                type="number"
                                                min={1}
                                                max={365}
                                                value={trialDays}
                                                onChange={(e) =>
                                                    setTrialDays(e.target.value)
                                                }
                                                className="h-8 w-16"
                                                aria-label="Días de trial"
                                            />
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    post(
                                                        'extend-trial',
                                                        'Trial extendido.',
                                                        {
                                                            days: Number(
                                                                trialDays,
                                                            ),
                                                        },
                                                    )
                                                }
                                            >
                                                Extender trial
                                            </Button>
                                        </div>
                                    ) : null}
                                </div>
                            </div>
                        )}
                    </Panel>

                    <Panel title="Activos monitoreados">
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
                                    ? 'mt-1 text-xs text-health-down'
                                    : 'mt-1 text-xs text-fg-3'
                            }
                        >
                            {overCap
                                ? 'Tenant en el tope: no se sincronizarán activos nuevos.'
                                : 'Tope efectivo del plan (o ajuste manual del tenant).'}
                        </p>
                    </Panel>

                    <Panel title={`Miembros (${members.length})`}>
                        {members.length === 0 ? (
                            <p className="text-sm text-fg-3">Sin miembros.</p>
                        ) : (
                            <ul className="flex flex-col gap-2 text-sm">
                                {members.map((member) => (
                                    <li
                                        key={member.id}
                                        className="flex items-center justify-between"
                                    >
                                        <span>
                                            {member.name}{' '}
                                            <span className="sam-meta">
                                                {member.email}
                                            </span>
                                        </span>
                                        <span className="sam-meta rounded bg-surface-2 px-1.5 py-0.5">
                                            {member.role}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel title={`Features (${features.length})`}>
                        {features.length === 0 ? (
                            <p className="text-sm text-fg-3">Sin features.</p>
                        ) : (
                            <ul className="flex flex-col gap-1.5 text-sm">
                                {features.map((feature) => (
                                    <li
                                        key={feature.key}
                                        className="flex items-center justify-between"
                                    >
                                        <span className="font-mono text-xs">
                                            {feature.key}
                                        </span>
                                        <span className="flex items-center gap-2">
                                            <span className="sam-meta">
                                                {feature.source}
                                            </span>
                                            <span
                                                className={
                                                    feature.enabled
                                                        ? 'text-health-ok'
                                                        : 'text-fg-3'
                                                }
                                            >
                                                {feature.enabled ? 'on' : 'off'}
                                            </span>
                                        </span>
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
                                            <td className="py-1 tabular-nums">
                                                {row.consumed}
                                            </td>
                                            <td className="py-1 tabular-nums">
                                                {row.included}
                                            </td>
                                            <td className="py-1 tabular-nums">
                                                {row.overage}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </Panel>
                </div>
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
