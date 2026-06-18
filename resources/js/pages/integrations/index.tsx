import { Head, router, usePage } from '@inertiajs/react';
import {
    Check,
    Copy,
    Loader2,
    MoreHorizontal,
    Plus,
    Plug,
    RefreshCw,
    Trash2,
    Wifi,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { ProviderTag } from '@/components/sam/provider-tag';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    deleteJson,
    postJson,
    putJson,
    readErrorMessage,
} from '@/lib/sam-fetch';
import { cn } from '@/lib/utils';
import type {
    AuthTypeOption,
    IntegrationHealth,
    IntegrationProviderOption,
    IntegrationRow,
    TenantIntegrationStatus,
} from '@/types/sam';

const HEALTH_DOT: Record<IntegrationHealth, string> = {
    ok: 'bg-health-ok',
    warn: 'bg-health-warn',
    down: 'bg-health-down',
    unknown: 'bg-health-unknown',
};

const STATUS_LABEL: Record<TenantIntegrationStatus, string> = {
    active: 'Activa',
    inactive: 'Inactiva',
    error: 'Error',
    pending: 'Pendiente',
};

interface IntegrationsIndexProps {
    integrations: IntegrationRow[];
    providers: IntegrationProviderOption[];
    authTypes: AuthTypeOption[];
}

function formatDateTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return date.toLocaleString('es', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

// ---- Webhook URL copy field ----

function WebhookField({ url }: { url: string }) {
    const [copied, setCopied] = useState(false);

    const copy = useCallback(async () => {
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            toast.success('URL del webhook copiada.');
            window.setTimeout(() => setCopied(false), 1500);
        } catch {
            toast.error('No se pudo copiar la URL.');
        }
    }, [url]);

    return (
        <div className="flex items-center gap-1.5 rounded-md border border-border bg-surface-2 px-2 py-1.5">
            <Wifi size={12} className="shrink-0 text-fg-3" />
            <code className="flex-1 truncate font-mono text-3xs text-fg-2">
                {url}
            </code>
            <Button
                size="icon"
                variant="ghost"
                className="size-6 shrink-0"
                onClick={copy}
                aria-label="Copiar URL del webhook"
            >
                {copied ? (
                    <Check size={12} className="text-health-ok" />
                ) : (
                    <Copy size={12} />
                )}
            </Button>
        </div>
    );
}

// ---- Integration card ----

interface IntegrationCardProps {
    integration: IntegrationRow;
    canManage: boolean;
    testing: boolean;
    onTest: () => void;
    onEdit: () => void;
    onDisconnect: () => void;
}

function IntegrationCard({
    integration,
    canManage,
    testing,
    onTest,
    onEdit,
    onDisconnect,
}: IntegrationCardProps) {
    return (
        <div className="flex flex-col gap-3 rounded-md border border-border bg-surface-1 p-4">
            <div className="flex items-start gap-2">
                <ProviderTag name={integration.provider} />
                <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-semibold">
                        {integration.name}
                    </div>
                    <div className="sam-meta truncate">
                        {integration.provider} · {integration.authType}
                    </div>
                </div>
                <span
                    className={cn(
                        'mt-1 size-2 shrink-0 rounded-full',
                        HEALTH_DOT[integration.health],
                    )}
                    aria-label={`Estado: ${STATUS_LABEL[integration.status]}`}
                    title={STATUS_LABEL[integration.status]}
                />
                {canManage ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                size="icon"
                                variant="ghost"
                                className="size-7 shrink-0"
                                aria-label="Acciones de la integración"
                            >
                                <MoreHorizontal size={14} />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                onSelect={onTest}
                                disabled={testing}
                            >
                                <RefreshCw size={13} /> Probar conexión
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={onEdit}>
                                <Plug size={13} /> Editar
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={onDisconnect}
                            >
                                <Trash2 size={13} /> Desconectar
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : null}
            </div>

            <div className="grid grid-cols-2 gap-2 text-2xs">
                <div>
                    <div className="sam-meta">Estado</div>
                    <div className="font-medium">
                        {STATUS_LABEL[integration.status]}
                    </div>
                </div>
                <div>
                    <div className="sam-meta">Último sync</div>
                    <div className="font-mono tabular-nums">
                        {formatDateTime(integration.lastSyncAt)}
                    </div>
                </div>
            </div>

            {integration.status === 'error' && integration.lastErrorMessage ? (
                <div className="rounded-md border border-health-down/40 bg-health-down/10 px-2 py-1.5 text-2xs text-health-down">
                    {integration.lastErrorMessage}
                </div>
            ) : null}

            {integration.webhook ? (
                <div className="flex flex-col gap-1.5">
                    <div className="flex items-center justify-between">
                        <span className="sam-meta">Webhook</span>
                        <span className="sam-meta">
                            recibido:{' '}
                            {formatDateTime(integration.webhook.lastReceivedAt)}
                        </span>
                    </div>
                    <WebhookField url={integration.webhook.url} />
                </div>
            ) : null}

            {canManage ? (
                <div className="flex items-center gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        className="flex-1"
                        onClick={onTest}
                        disabled={testing}
                    >
                        {testing ? (
                            <Loader2 size={12} className="animate-spin" />
                        ) : (
                            <RefreshCw size={12} />
                        )}
                        Probar
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        className="flex-1"
                        onClick={onEdit}
                    >
                        Editar
                    </Button>
                </div>
            ) : null}
        </div>
    );
}

// ---- Connect dialog ----

interface ConnectDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    providers: IntegrationProviderOption[];
    authTypes: AuthTypeOption[];
    teamSlug: string | null;
}

function ConnectDialog({
    open,
    onOpenChange,
    providers,
    authTypes,
    teamSlug,
}: ConnectDialogProps) {
    const [providerId, setProviderId] = useState('');
    const [name, setName] = useState('');
    const [authType, setAuthType] = useState(authTypes[0]?.value ?? '');
    const [credentials, setCredentials] = useState('');
    const [config, setConfig] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const reset = useCallback(() => {
        setProviderId('');
        setName('');
        setAuthType(authTypes[0]?.value ?? '');
        setCredentials('');
        setConfig('');
    }, [authTypes]);

    const handleOpenChange = (next: boolean) => {
        if (!next) {
            reset();
        }

        onOpenChange(next);
    };

    const submit = useCallback(async () => {
        if (teamSlug === null) {
            toast.error('No hay equipo activo.');

            return;
        }

        if (providerId === '' || name.trim() === '' || credentials === '') {
            toast.error('Proveedor, nombre y credenciales son obligatorios.');

            return;
        }

        let parsedConfig: Record<string, unknown> | undefined;

        if (config.trim() !== '') {
            try {
                parsedConfig = JSON.parse(config) as Record<string, unknown>;
            } catch {
                toast.error('La configuración debe ser JSON válido.');

                return;
            }
        }

        setSubmitting(true);

        const response = await postJson(`/${teamSlug}/integrations`, {
            provider_id: Number(providerId),
            name: name.trim(),
            auth_type: authType,
            credentials,
            config: parsedConfig,
        });

        setSubmitting(false);

        if (response.ok) {
            toast.success('Integración conectada.');
            reset();
            onOpenChange(false);
            router.reload({ only: ['integrations'] });

            return;
        }

        if (response.status === 403) {
            toast.error('No tienes permisos para conectar integraciones.');

            return;
        }

        toast.error(
            (await readErrorMessage(response)) ??
                'No se pudo conectar la integración.',
        );
    }, [
        teamSlug,
        providerId,
        name,
        authType,
        credentials,
        config,
        reset,
        onOpenChange,
    ]);

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Conectar integración</DialogTitle>
                    <DialogDescription>
                        Elige un proveedor y proporciona sus credenciales. Se
                        creará automáticamente un endpoint de webhook.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="connect-provider">Proveedor</Label>
                        <Select
                            value={providerId}
                            onValueChange={setProviderId}
                        >
                            <SelectTrigger id="connect-provider">
                                <SelectValue placeholder="Selecciona un proveedor" />
                            </SelectTrigger>
                            <SelectContent>
                                {providers.map((provider) => (
                                    <SelectItem
                                        key={provider.id}
                                        value={String(provider.id)}
                                    >
                                        {provider.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="connect-name">Nombre</Label>
                        <Input
                            id="connect-name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            placeholder="Mi conexión Samsara"
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="connect-auth">
                            Tipo de autenticación
                        </Label>
                        <Select value={authType} onValueChange={setAuthType}>
                            <SelectTrigger id="connect-auth">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {authTypes.map((type) => (
                                    <SelectItem
                                        key={type.value}
                                        value={type.value}
                                    >
                                        {type.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="connect-credentials">
                            Credenciales
                        </Label>
                        <Input
                            id="connect-credentials"
                            type="password"
                            value={credentials}
                            onChange={(e) => setCredentials(e.target.value)}
                            placeholder="API key, token u OAuth secret"
                            autoComplete="off"
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="connect-config">
                            Configuración (JSON, opcional)
                        </Label>
                        <textarea
                            id="connect-config"
                            value={config}
                            onChange={(e) => setConfig(e.target.value)}
                            placeholder='{"refresh_interval": 300}'
                            rows={3}
                            className="rounded-md border border-border bg-surface-2 px-3 py-2 font-mono text-xs"
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        variant="ghost"
                        onClick={() => handleOpenChange(false)}
                        disabled={submitting}
                    >
                        Cancelar
                    </Button>
                    <Button onClick={submit} disabled={submitting}>
                        {submitting ? (
                            <Loader2 size={14} className="animate-spin" />
                        ) : null}
                        Conectar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ---- Edit dialog ----

interface EditDialogProps {
    integration: IntegrationRow | null;
    onOpenChange: (open: boolean) => void;
    teamSlug: string | null;
}

function EditDialog({ integration, onOpenChange, teamSlug }: EditDialogProps) {
    const [name, setName] = useState('');
    const [credentials, setCredentials] = useState('');
    const [config, setConfig] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [hydratedId, setHydratedId] = useState<number | null>(null);

    // Sync local form state when a different integration is opened.
    if (integration !== null && integration.id !== hydratedId) {
        setHydratedId(integration.id);
        setName(integration.name);
        setCredentials('');
        setConfig(
            integration.config
                ? JSON.stringify(integration.config, null, 2)
                : '',
        );
    }

    const submit = useCallback(async () => {
        if (integration === null || teamSlug === null) {
            return;
        }

        let parsedConfig: Record<string, unknown> | undefined;

        if (config.trim() !== '') {
            try {
                parsedConfig = JSON.parse(config) as Record<string, unknown>;
            } catch {
                toast.error('La configuración debe ser JSON válido.');

                return;
            }
        }

        const body: Record<string, unknown> = { name: name.trim() };

        if (credentials !== '') {
            body.credentials = credentials;
        }

        if (parsedConfig !== undefined) {
            body.config = parsedConfig;
        }

        setSubmitting(true);

        const response = await putJson(
            `/${teamSlug}/integrations/${integration.id}`,
            body,
        );

        setSubmitting(false);

        if (response.ok) {
            toast.success('Integración actualizada.');
            onOpenChange(false);
            router.reload({ only: ['integrations'] });

            return;
        }

        if (response.status === 403) {
            toast.error('No tienes permisos para editar integraciones.');

            return;
        }

        toast.error(
            (await readErrorMessage(response)) ??
                'No se pudo actualizar la integración.',
        );
    }, [integration, teamSlug, name, credentials, config, onOpenChange]);

    return (
        <Dialog
            open={integration !== null}
            onOpenChange={(next) => {
                if (!next) {
                    onOpenChange(false);
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Editar integración</DialogTitle>
                    <DialogDescription>
                        Actualiza el nombre, configuración o rota las
                        credenciales. Deja las credenciales en blanco para
                        conservarlas.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="edit-name">Nombre</Label>
                        <Input
                            id="edit-name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="edit-credentials">
                            Credenciales (opcional)
                        </Label>
                        <Input
                            id="edit-credentials"
                            type="password"
                            value={credentials}
                            onChange={(e) => setCredentials(e.target.value)}
                            placeholder="Dejar en blanco para no cambiar"
                            autoComplete="off"
                        />
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="edit-config">
                            Configuración (JSON, opcional)
                        </Label>
                        <textarea
                            id="edit-config"
                            value={config}
                            onChange={(e) => setConfig(e.target.value)}
                            rows={4}
                            className="rounded-md border border-border bg-surface-2 px-3 py-2 font-mono text-xs"
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        disabled={submitting}
                    >
                        Cancelar
                    </Button>
                    <Button onClick={submit} disabled={submitting}>
                        {submitting ? (
                            <Loader2 size={14} className="animate-spin" />
                        ) : null}
                        Guardar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ---- Disconnect dialog ----

interface DisconnectDialogProps {
    integration: IntegrationRow | null;
    onOpenChange: (open: boolean) => void;
    teamSlug: string | null;
}

function DisconnectDialog({
    integration,
    onOpenChange,
    teamSlug,
}: DisconnectDialogProps) {
    const [submitting, setSubmitting] = useState(false);

    const confirm = useCallback(async () => {
        if (integration === null || teamSlug === null) {
            return;
        }

        setSubmitting(true);

        const response = await deleteJson(
            `/${teamSlug}/integrations/${integration.id}`,
        );

        setSubmitting(false);

        if (response.ok) {
            toast.success('Integración desconectada.');
            onOpenChange(false);
            router.reload({ only: ['integrations'] });

            return;
        }

        if (response.status === 403) {
            toast.error('No tienes permisos para desconectar integraciones.');

            return;
        }

        toast.error(
            (await readErrorMessage(response)) ??
                'No se pudo desconectar la integración.',
        );
    }, [integration, teamSlug, onOpenChange]);

    return (
        <Dialog
            open={integration !== null}
            onOpenChange={(next) => {
                if (!next) {
                    onOpenChange(false);
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Desconectar integración</DialogTitle>
                    <DialogDescription>
                        {integration
                            ? `¿Seguro que deseas desconectar "${integration.name}"? Se marcará como inactiva y se eliminará.`
                            : ''}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        disabled={submitting}
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={confirm}
                        disabled={submitting}
                    >
                        {submitting ? (
                            <Loader2 size={14} className="animate-spin" />
                        ) : null}
                        Desconectar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ---- Page ----

export default function IntegrationsIndex() {
    const page = usePage();
    const pageProps = page.props as unknown as IntegrationsIndexProps;
    const integrations = useMemo(
        () => pageProps.integrations ?? [],
        [pageProps.integrations],
    );
    const providers = pageProps.providers ?? [];
    const authTypes = pageProps.authTypes ?? [];
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const permissions = page.props.auth?.permissions ?? [];
    const canManage = permissions.includes('integrations.manage');

    const [connectOpen, setConnectOpen] = useState(false);
    const [editing, setEditing] = useState<IntegrationRow | null>(null);
    const [disconnecting, setDisconnecting] = useState<IntegrationRow | null>(
        null,
    );
    const [testingId, setTestingId] = useState<number | null>(null);

    const alertCount = integrations.filter((i) => i.health !== 'ok').length;

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
                toast.error('No tienes permisos para probar integraciones.');

                return;
            }

            if (!response.ok) {
                toast.error(
                    (await readErrorMessage(response)) ??
                        'No se pudo probar la conexión.',
                );
                router.reload({ only: ['integrations'] });

                return;
            }

            const payload = (await response.json()) as {
                data?: { success?: boolean; message?: string };
            };
            const result = payload.data;

            if (result?.success) {
                toast.success(result.message ?? 'Conexión exitosa.');
            } else {
                toast.error(result?.message ?? 'La conexión falló.');
            }

            router.reload({ only: ['integrations'] });
        },
        [teamSlug],
    );

    return (
        <div className="flex h-full flex-col overflow-hidden">
            <Head title="Integraciones" />

            <PageHeader
                title="Integraciones"
                meta={
                    <span className="sam-meta">
                        {integrations.length} conectadas · {alertCount} con
                        alertas
                    </span>
                }
                actions={
                    canManage ? (
                        <Button size="sm" onClick={() => setConnectOpen(true)}>
                            <Plus size={14} /> Conectar integración
                        </Button>
                    ) : null
                }
                className="shrink-0 border-b border-border bg-background px-5 py-3"
            />

            <div className="flex-1 overflow-y-auto p-5">
                {integrations.length === 0 ? (
                    <EmptyState
                        icon={Plug}
                        title="Sin integraciones"
                        description="Conecta tu primer proveedor (Samsara, Motive, …) para empezar a recibir eventos de tu flota."
                        action={
                            canManage ? (
                                <Button
                                    size="sm"
                                    onClick={() => setConnectOpen(true)}
                                >
                                    <Plus size={14} /> Conectar integración
                                </Button>
                            ) : null
                        }
                    />
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {integrations.map((integration) => (
                            <IntegrationCard
                                key={integration.id}
                                integration={integration}
                                canManage={canManage}
                                testing={testingId === integration.id}
                                onTest={() => void runTest(integration)}
                                onEdit={() => setEditing(integration)}
                                onDisconnect={() =>
                                    setDisconnecting(integration)
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
                integration={editing}
                onOpenChange={() => setEditing(null)}
                teamSlug={teamSlug}
            />
            <DisconnectDialog
                integration={disconnecting}
                onOpenChange={() => setDisconnecting(null)}
                teamSlug={teamSlug}
            />
        </div>
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
