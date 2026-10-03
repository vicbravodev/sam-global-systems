import { Gauge } from 'lucide-react';
import type {
    Tenant,
    Feature,
    Pending,
} from '@/components/sam/admin/tenant/types';
import { visit } from '@/components/sam/admin/tenant/visit';
import { Panel } from '@/components/sam/panel';
import { EmptyState } from '@/components/ui/empty-state';
import { Switch } from '@/components/ui/switch';
import { humanizeCode, meterLabel } from '@/lib/labels';
import { update as updateFeature } from '@/routes/admin/tenants/features';

const SOURCE_LABELS: Record<string, string> = {
    default_plan: 'Del plan',
    manual_override: 'Manual',
    promo: 'Promoción',
    beta_access: 'Beta',
};

export function FeaturesTab({
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
        <Panel
            size="lg"
            bodyClassName="p-4"
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
        </Panel>
    );
}
