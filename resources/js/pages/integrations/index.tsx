import { Head, router, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    CircleDashed,
    Plug,
    Plus,
    Truck,
    Users,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { toast } from 'sonner';
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
import { ListPage } from '@/components/sam/list-page';
import { PulseStat, PulseStrip } from '@/components/sam/pulse-strip';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { useBroadcastReload } from '@/hooks/use-team-broadcasts';
import { formatNumber } from '@/lib/format';
import { deleteJson, postJson, readErrorMessage } from '@/lib/sam-fetch';
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

const RELOAD_PROPS = ['integrations', 'summary'];

function summarize(integrations: IntegrationRow[]): IntegrationsSummary {
    const count = (status: TenantIntegrationStatus) =>
        integrations.filter((i) => summaryStatus(i) === status).length;

    return {
        total: integrations.length,
        working: count('active'),
        attention: count('error'),
        pending: count('pending'),
        inactive: count('inactive'),
        events24h: integrations.reduce((n, i) => n + (i.events24h ?? 0), 0),
        assets: 0,
        monitored: 0,
        drivers: 0,
    };
}

const FILTER_EMPTY: Record<TenantIntegrationStatus, string> = {
    active: 'Ninguna conexión está funcionando ahora mismo.',
    error: 'Ninguna conexión requiere atención. Todo en orden.',
    pending: 'No hay conexiones pendientes de configurar.',
    inactive: 'No hay conexiones desactivadas.',
};

export default function IntegrationsIndex() {
    // Status flips from the feed (circuit opened) or another operator.
    useBroadcastReload(
        { 'integration.status_changed': RELOAD_PROPS },
        { debounceMs: 500 },
    );
    const page = usePage();
    const pageProps = page.props as unknown as IntegrationsIndexProps;
    const integrations = useMemo(
        () => pageProps.integrations ?? [],
        [pageProps.integrations],
    );
    const summary = pageProps.summary ?? summarize(integrations);
    const providers = pageProps.providers ?? [];
    const authTypes = pageProps.authTypes ?? [];
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const permissions = page.props.auth?.permissions ?? [];
    const canManage = permissions.includes('integrations.manage');

    const [filter, setFilter] = useState<TenantIntegrationStatus | null>(null);
    const [connectOpen, setConnectOpen] = useState(false);
    const [editing, setEditing] = useState<EditTarget | null>(null);
    const [disconnecting, setDisconnecting] = useState<IntegrationRow | null>(
        null,
    );
    const [testingId, setTestingId] = useState<number | null>(null);

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

    const toggle = (status: TenantIntegrationStatus) => () =>
        setFilter((current) => (current === status ? null : status));

    const openEdit = (integration: IntegrationRow, mode: EditMode) =>
        setEditing({ integration, mode });

    const runTest = useCallback(
        async (integration: IntegrationRow) => {
            if (teamSlug === null) {
                toast.error('No hay equipo activo.');

                return;
            }

            setTestingId(integration.id);

            const response = await postJson(
                `/${teamSlug}/integrations/${integration.id}/test`,
            );

            setTestingId(null);

            if (response.status === 403) {
                toast.error('No tienes permisos para probar conexiones.');

                return;
            }

            if (!response.ok) {
                toast.error(
                    (await readErrorMessage(response)) ??
                        'No se pudo probar la conexión.',
                );
                router.reload({ only: RELOAD_PROPS });

                return;
            }

            const payload = (await response.json()) as {
                data?: { success?: boolean; message?: string };
            };

            if (payload.data?.success) {
                toast.success(
                    `Conexión correcta: SAM puede leer los datos de ${integration.provider}.`,
                );
            } else {
                toast.error(
                    'La prueba falló. En la tarjeta te decimos qué pasó y cómo resolverlo.',
                );
            }

            router.reload({ only: RELOAD_PROPS });
        },
        [teamSlug],
    );

    const disconnect = useCallback(async () => {
        if (disconnecting === null || teamSlug === null) {
            return;
        }

        const response = await deleteJson(
            `/${teamSlug}/integrations/${disconnecting.id}`,
        );

        if (response.ok) {
            toast.success('Conexión eliminada.');
            setDisconnecting(null);
            router.reload({ only: RELOAD_PROPS });

            return;
        }

        if (response.status === 403) {
            toast.error('No tienes permisos para desconectar proveedores.');

            return;
        }

        toast.error(
            (await readErrorMessage(response)) ??
                'No se pudo desconectar el proveedor.',
        );
    }, [disconnecting, teamSlug]);

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
                <PulseStrip>
                    <PulseStat
                        label="Conexiones"
                        value={summary.total}
                        icon={Plug}
                        hint="con tus proveedores"
                        onClick={() => setFilter(null)}
                        active={filter === null}
                    />
                    <PulseStat
                        label="Funcionando"
                        value={summary.working}
                        icon={CheckCircle2}
                        tone={summary.working > 0 ? 'ok' : 'neutral'}
                        hint="reciben datos"
                        onClick={toggle('active')}
                        active={filter === 'active'}
                    />
                    <PulseStat
                        label="Atención"
                        value={summary.attention}
                        icon={AlertTriangle}
                        tone={summary.attention > 0 ? 'critical' : 'neutral'}
                        hint={
                            summary.attention > 0
                                ? 'sin datos hasta resolverlo'
                                : 'todo en orden'
                        }
                        onClick={toggle('error')}
                        active={filter === 'error'}
                    />
                    <PulseStat
                        label="Pendientes"
                        value={summary.pending}
                        icon={CircleDashed}
                        tone={summary.pending > 0 ? 'warn' : 'neutral'}
                        hint="falta terminar de configurar"
                        onClick={toggle('pending')}
                        active={filter === 'pending'}
                    />
                    <PulseStat
                        label="Eventos 24 h"
                        value={formatNumber(summary.events24h)}
                        icon={Activity}
                        tone="info"
                        live={summary.events24h > 0}
                        hint="recibidos de tus proveedores"
                    />
                    <PulseStat
                        label="Unidades"
                        value={formatNumber(summary.assets)}
                        icon={Truck}
                        hint={`${formatNumber(summary.monitored)} monitoreadas`}
                    />
                    <PulseStat
                        label="Conductores"
                        value={formatNumber(summary.drivers)}
                        icon={Users}
                        hint="traídos del proveedor"
                    />
                </PulseStrip>
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
                                testing={testingId === integration.id}
                                onTest={() => void runTest(integration)}
                                onEdit={() => openEdit(integration, 'edit')}
                                onUpdateKey={() =>
                                    openEdit(integration, 'credentials')
                                }
                                onDisconnect={() =>
                                    setDisconnecting(integration)
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
                open={disconnecting !== null}
                title="Desconectar proveedor"
                description={
                    disconnecting
                        ? `SAM dejará de recibir ubicaciones, eventos y alertas de «${disconnecting.name}». Tus unidades, conductores e historial se conservan. Para volver a conectarla tendrás que pegar la clave de acceso otra vez.`
                        : ''
                }
                confirmLabel="Desconectar"
                onConfirm={disconnect}
                onOpenChange={(open) => {
                    if (!open) {
                        setDisconnecting(null);
                    }
                }}
            />
        </ListPage>
    );
}

IntegrationsIndex.layout = (props: {
    currentTeam?: { slug: string } | null;
}) => ({
    breadcrumbs: [
        {
            title: 'Integraciones',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/integrations`
                : '/integrations',
        },
    ],
});
