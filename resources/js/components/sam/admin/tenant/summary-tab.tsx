import { CapMeterBar } from '@/components/sam/admin/tenant/billing';
import { SetupChecklist } from '@/components/sam/admin/tenant/setup-checklist';
import type {
    Member,
    Setup,
    AdminTenantShowProps,
} from '@/components/sam/admin/tenant/types';
import { BillingPill } from '@/components/sam/billing/panel';
import { EntityAvatar } from '@/components/sam/entity-avatar';
import { Panel } from '@/components/sam/panel';
import { Button } from '@/components/ui/button';

export function SummaryTab({
    setup,
    owner,
    assetUsage,
    onGo,
}: {
    setup: Setup;
    owner: Member | null;
    assetUsage: AdminTenantShowProps['assetUsage'];
    onGo: (tab: string) => void;
}) {
    return (
        <div className="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <SetupChecklist setup={setup} onGo={onGo} />
            <div className="grid content-start gap-4">
                <Panel size="lg" bodyClassName="p-4" title="Responsable">
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
                                <BillingPill tone="warn" className="ml-auto">
                                    Acceso pendiente
                                </BillingPill>
                            ) : null}
                        </div>
                    ) : (
                        <p className="text-sm text-fg-3">
                            Sin responsable asignado.
                        </p>
                    )}
                </Panel>
                <Panel
                    size="lg"
                    bodyClassName="p-4"
                    title="Unidades vigiladas"
                    action={
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => onGo('billing')}
                        >
                            Ver cobro
                        </Button>
                    }
                >
                    <CapMeterBar usage={assetUsage} />
                </Panel>
            </div>
        </div>
    );
}
