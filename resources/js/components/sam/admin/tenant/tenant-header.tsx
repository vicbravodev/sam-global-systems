import { router } from '@inertiajs/react';
import { UserCog } from 'lucide-react';
import { useState } from 'react';
import type { TabKey } from '@/components/sam/admin/tenant/tabs';
import type {
    Tenant,
    Setup,
    AdminTenantShowProps,
} from '@/components/sam/admin/tenant/types';
import { SubscriptionPill } from '@/components/sam/admin-tenant-status';
import { EntityAvatar } from '@/components/sam/entity-avatar';
import { StatusBadge } from '@/components/sam/status-badge';
import { TabBar } from '@/components/sam/tab-bar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/format';
import { timezoneLabel } from '@/lib/timezones';
import { store as impersonateStore } from '@/routes/admin/impersonate';

export function TenantHeader({
    tenant,
    subscription,
    setup,
    membersCount,
    featuresCount,
    tab,
    onTabChange,
}: {
    tenant: Tenant;
    subscription: AdminTenantShowProps['subscription'];
    setup: Setup;
    membersCount: number;
    featuresCount: number;
    tab: TabKey;
    onTabChange: (next: string) => void;
}) {
    const [entering, setEntering] = useState(false);

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
        <header className="shrink-0 border-b border-border bg-surface-1 px-5 pt-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <EntityAvatar name={tenant.name} shape="square" size={36} />
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="sam-h2 min-w-0 truncate">
                                {tenant.name}
                            </h1>
                            <SubscriptionPill
                                status={subscription?.status ?? null}
                            />
                            {setup.completed === setup.total ? (
                                <StatusBadge
                                    size="sm"
                                    tone="ok"
                                    label="Operando"
                                />
                            ) : (
                                <StatusBadge
                                    size="sm"
                                    tone="warn"
                                    label={
                                        <>
                                            En alta · {setup.completed}/
                                            {setup.total}
                                        </>
                                    }
                                />
                            )}
                        </div>
                        <p className="mt-0.5 truncate text-xs text-fg-3">
                            <span className="font-mono">{tenant.slug}</span>
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
                onChange={onTabChange}
                items={[
                    { key: 'summary', label: 'Resumen' },
                    { key: 'billing', label: 'Cobro' },
                    {
                        key: 'members',
                        label: 'Miembros',
                        count: membersCount,
                    },
                    {
                        key: 'features',
                        label: 'Funciones',
                        count: featuresCount,
                    },
                    { key: 'usage', label: 'Consumo' },
                    { key: 'settings', label: 'Ajustes' },
                ]}
            />
        </header>
    );
}
