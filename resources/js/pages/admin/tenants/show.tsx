import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { AdminTenantBilling } from '@/components/sam/admin/tenant/billing';
import { FeaturesTab } from '@/components/sam/admin/tenant/features-tab';
import { MembersTab } from '@/components/sam/admin/tenant/members-tab';
import { SettingsTab } from '@/components/sam/admin/tenant/settings-tab';
import { SummaryTab } from '@/components/sam/admin/tenant/summary-tab';
import { initialTab, syncTabToUrl } from '@/components/sam/admin/tenant/tabs';
import type { TabKey } from '@/components/sam/admin/tenant/tabs';
import { TenantHeader } from '@/components/sam/admin/tenant/tenant-header';
import type {
    AdminTenantShowProps,
    Pending,
} from '@/components/sam/admin/tenant/types';
import { UsageTab } from '@/components/sam/admin/tenant/usage-tab';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import {
    index as adminTenantsIndex,
    show as adminTenantShow,
} from '@/routes/admin/tenants';

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

    return (
        <>
            <Head title={tenant.name} />
            <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
                <TenantHeader
                    tenant={tenant}
                    subscription={subscription}
                    setup={setup}
                    membersCount={members.length}
                    featuresCount={features.length}
                    tab={tab}
                    onTabChange={setTab}
                />

                <div className="min-h-0 flex-1 overflow-y-auto p-5">
                    {tab === 'summary' ? (
                        <SummaryTab
                            setup={setup}
                            owner={owner}
                            assetUsage={assetUsage}
                            onGo={setTab}
                        />
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
