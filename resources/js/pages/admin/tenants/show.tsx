import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, UserCog } from 'lucide-react';
import { Button } from '@/components/ui/button';
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

interface AdminTenantShowProps {
    tenant: Tenant;
    subscription: Subscription | null;
    members: Member[];
    features: Feature[];
    usage: UsageRow[];
}

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
}: AdminTenantShowProps) {
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
                                <dd>{subscription.status}</dd>
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
                                Sin suscripción.
                            </p>
                        )}
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
        </div>
    );
}
