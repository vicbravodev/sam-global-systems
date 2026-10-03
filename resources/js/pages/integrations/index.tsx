import type { SharedPageProps } from '@inertiajs/core';
import { Head, router, usePage } from '@inertiajs/react';
import { Plug, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import { ConnectDialog } from '@/components/sam/integrations/connect-dialog';
import { EditDialog } from '@/components/sam/integrations/edit-dialog';
import type {
    EditMode,
    EditTarget,
} from '@/components/sam/integrations/edit-dialog';
import { IntegrationCard } from '@/components/sam/integrations/integration-card';
import {
    STATUS_ORDER,
    summaryStatus,
} from '@/components/sam/integrations/integration-state';
import { IntegrationsEmpty } from '@/components/sam/integrations/integrations-empty';
import { IntegrationsPulse } from '@/components/sam/integrations/integrations-pulse';
import {
    FILTER_EMPTY,
    INTEGRATIONS_RELOAD_PROPS as RELOAD_PROPS,
    summarizeIntegrations,
} from '@/components/sam/integrations/lib';
import { useIntegrationActions } from '@/components/sam/integrations/use-integration-actions';
import { ListPage } from '@/components/sam/list-page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { useBroadcastReload } from '@/hooks/use-team-broadcasts';
import integrationRoutes from '@/routes/integrations';
import type {
    AuthTypeOption,
    IntegrationProviderOption,
    IntegrationRow,
    IntegrationsSummary,
    TenantIntegrationStatus,
} from '@/types/sam';

interface IntegrationsIndexProps {
    integrations: IntegrationRow[];
    summary?: IntegrationsSummary;
    providers: IntegrationProviderOption[];
    authTypes: AuthTypeOption[];
}

export default function IntegrationsIndex(pageProps: IntegrationsIndexProps) {
    // Status flips from the feed (circuit opened) or another operator.
    useBroadcastReload(
        { 'integration.status_changed': RELOAD_PROPS },
        { debounceMs: 500 },
    );
    const page = usePage();
    const integrations = useMemo(
        () => pageProps.integrations ?? [],
        [pageProps.integrations],
    );
    const summary = pageProps.summary ?? summarizeIntegrations(integrations);
    const providers = pageProps.providers ?? [];
    const authTypes = pageProps.authTypes ?? [];
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const permissions = page.props.auth?.permissions ?? [];
    const canManage = permissions.includes('integrations.manage');

    const [filter, setFilter] = useState<TenantIntegrationStatus | null>(null);
    const [connectOpen, setConnectOpen] = useState(false);
    const [editing, setEditing] = useState<EditTarget | null>(null);
    const actions = useIntegrationActions(teamSlug);

    const visible = useMemo(
        () =>
            integrations
                .filter((i) => filter === null || summaryStatus(i) === filter)
                .sort(
                    (a, b) =>
                        STATUS_ORDER[summaryStatus(a)] -
                            STATUS_ORDER[summaryStatus(b)] || b.id - a.id,
                ),
        [integrations, filter],
    );

    const openEdit = (integration: IntegrationRow, mode: EditMode) =>
        setEditing({ integration, mode });

    const hasAny = integrations.length > 0;

    return (
        <ListPage
            title="Integraciones"
            meta={
                hasAny ? (
                    <span className="text-xs text-fg-3">
                        <span className="font-medium text-fg-1">
                            {summary.total}
                        </span>{' '}
                        {summary.total === 1 ? 'conexión' : 'conexiones'}
                        {summary.attention > 0 ? (
                            <>
                                {' · '}
                                <span className="text-severity-critical">
                                    {summary.attention}{' '}
                                    {summary.attention === 1
                                        ? 'requiere'
                                        : 'requieren'}{' '}
                                    atención
                                </span>
                            </>
                        ) : null}
                    </span>
                ) : null
            }
            description="De aquí salen las ubicaciones, eventos y alertas de tu flota. Si algo falla, te decimos cómo arreglarlo."
            actions={
                canManage && hasAny ? (
                    <Button size="sm" onClick={() => setConnectOpen(true)}>
                        <Plus size={14} /> Conectar proveedor
                    </Button>
                ) : null
            }
        >
            <Head title="Integraciones" />

            {hasAny ? (
                <IntegrationsPulse
                    summary={summary}
                    filter={filter}
                    setFilter={setFilter}
                />
            ) : null}

            <div className="min-h-0 flex-1 overflow-y-auto">
                {!hasAny ? (
                    <IntegrationsEmpty
                        canManage={canManage}
                        onConnect={() => setConnectOpen(true)}
                    />
                ) : visible.length === 0 && filter !== null ? (
                    <EmptyState
                        icon={Plug}
                        title="Nada por aquí"
                        description={FILTER_EMPTY[filter]}
                        action={
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setFilter(null)}
                            >
                                Ver todas las conexiones
                            </Button>
                        }
                    />
                ) : (
                    <div className="mx-auto flex max-w-5xl flex-col gap-3 p-4 sm:p-5">
                        {visible.map((integration) => (
                            <IntegrationCard
                                key={integration.id}
                                integration={integration}
                                canManage={canManage}
                                teamSlug={teamSlug}
                                testing={actions.testingId === integration.id}
                                onTest={() => void actions.runTest(integration)}
                                onEdit={() => openEdit(integration, 'edit')}
                                onUpdateKey={() =>
                                    openEdit(integration, 'credentials')
                                }
                                onDisconnect={() =>
                                    actions.setDisconnecting(integration)
                                }
                                onWebhookSecretSaved={() =>
                                    router.reload({ only: RELOAD_PROPS })
                                }
                            />
                        ))}
                    </div>
                )}
            </div>

            <ConnectDialog
                open={connectOpen}
                onOpenChange={setConnectOpen}
                providers={providers}
                authTypes={authTypes}
                teamSlug={teamSlug}
            />
            <EditDialog
                target={editing}
                onClose={() => setEditing(null)}
                teamSlug={teamSlug}
            />
            <ConfirmDialog
                open={actions.disconnecting !== null}
                title="Desconectar proveedor"
                description={
                    actions.disconnecting
                        ? `SAM dejará de recibir ubicaciones, eventos y alertas de «${actions.disconnecting.name}». Tus unidades, conductores e historial se conservan. Para volver a conectarla tendrás que pegar la clave de acceso otra vez.`
                        : ''
                }
                confirmLabel="Desconectar"
                onConfirm={actions.disconnect}
                onOpenChange={(open) => {
                    if (!open) {
                        actions.setDisconnecting(null);
                    }
                }}
            />
        </ListPage>
    );
}

IntegrationsIndex.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'Integraciones',
            href: props.currentTeam
                ? integrationRoutes.index.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
