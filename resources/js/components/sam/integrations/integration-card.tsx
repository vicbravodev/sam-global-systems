import {
    AlertTriangle,
    CheckCircle2,
    ChevronDown,
    CircleDashed,
    KeyRound,
    Loader2,
    MoreHorizontal,
    Pencil,
    PauseCircle,
    RefreshCw,
    Trash2,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { relativeLabel } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { IntegrationRow } from '@/types/sam';
import { CopyField } from './copy-field';
import {
    capabilityLabel,
    integrationState,
    TONE_DOT,
    TONE_SURFACE,
    TONE_TEXT,
} from './integration-state';
import type { IntegrationTone } from './integration-state';
import { ProviderTile } from './provider-tile';

const TONE_ICON: Record<IntegrationTone, LucideIcon> = {
    ok: CheckCircle2,
    warn: AlertTriangle,
    critical: XCircle,
    neutral: PauseCircle,
};

interface Props {
    integration: IntegrationRow;
    canManage: boolean;
    testing: boolean;
    onTest: () => void;
    onEdit: () => void;
    onUpdateKey: () => void;
    onDisconnect: () => void;
}

export function IntegrationCard({
    integration,
    canManage,
    testing,
    onTest,
    onEdit,
    onUpdateKey,
    onDisconnect,
}: Props) {
    const state = integrationState(integration);
    const StateIcon =
        integration.status === 'pending' ? CircleDashed : TONE_ICON[state.tone];
    const needsHand = integration.status !== 'active';
    const capabilities = integration.capabilities ?? [];

    const primary =
        state.fix === 'credentials' ? (
            <Button
                size="sm"
                variant={needsHand ? 'default' : 'outline'}
                onClick={onUpdateKey}
            >
                <KeyRound size={13} /> Actualizar clave
            </Button>
        ) : (
            <Button
                size="sm"
                variant={needsHand ? 'default' : 'outline'}
                onClick={onTest}
                disabled={testing}
            >
                {testing ? (
                    <Loader2 size={13} className="animate-spin" />
                ) : (
                    <RefreshCw size={13} />
                )}
                {testing ? 'Probando…' : 'Probar conexión'}
            </Button>
        );

    return (
        <article
            className="flex flex-col overflow-hidden rounded-lg border border-border bg-surface-1"
            aria-label={integration.name}
        >
            <div className="flex flex-wrap items-start gap-x-3 gap-y-3 p-4">
                <ProviderTile name={integration.provider} />

                <div className="flex min-w-0 flex-1 basis-56 flex-col gap-1">
                    <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                        <h3 className="min-w-0 truncate text-base font-semibold text-fg-1">
                            {integration.name}
                        </h3>
                        <span
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-sm border px-1.5 py-0.5 text-3xs font-semibold tracking-label whitespace-nowrap',
                                TONE_SURFACE[state.tone],
                                TONE_TEXT[state.tone],
                            )}
                        >
                            <span
                                className={cn(
                                    'size-1.5 rounded-full',
                                    TONE_DOT[state.tone],
                                )}
                                aria-hidden="true"
                            />
                            {state.badge}
                        </span>
                    </div>
                    <p className="text-2xs text-fg-3">
                        {integration.provider}
                        {integration.connectedAt
                            ? ` · conectada el ${formatDate(integration.connectedAt)}`
                            : ''}
                    </p>
                </div>

                {canManage ? (
                    <div className="flex shrink-0 items-center gap-1.5">
                        {primary}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="size-8"
                                    aria-label={`Más acciones para ${integration.name}`}
                                >
                                    <MoreHorizontal size={15} />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {state.fix === 'credentials' ? (
                                    <DropdownMenuItem
                                        onSelect={onTest}
                                        disabled={testing}
                                    >
                                        <RefreshCw size={13} /> Probar conexión
                                    </DropdownMenuItem>
                                ) : (
                                    <DropdownMenuItem onSelect={onUpdateKey}>
                                        <KeyRound size={13} /> Actualizar clave
                                    </DropdownMenuItem>
                                )}
                                <DropdownMenuItem onSelect={onEdit}>
                                    <Pencil size={13} /> Editar conexión
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
                    </div>
                ) : null}

                <div
                    className={cn(
                        'flex w-full items-start gap-2.5 rounded-md border px-3 py-2.5',
                        needsHand || state.warning
                            ? TONE_SURFACE[state.tone]
                            : 'border-transparent bg-surface-2',
                    )}
                >
                    <StateIcon
                        size={15}
                        className={cn('mt-px shrink-0', TONE_TEXT[state.tone])}
                        aria-hidden
                    />
                    <div className="flex min-w-0 flex-col gap-0.5">
                        <p className="text-sm font-medium text-fg-1">
                            {state.headline}
                        </p>
                        {state.warning ? (
                            <p className="text-xs text-fg-2">
                                Aviso reciente: {state.warning}. Si se repite,
                                {state.fix === 'credentials'
                                    ? ' actualiza la clave.'
                                    : ' prueba la conexión.'}
                            </p>
                        ) : null}
                        {state.hint ? (
                            <p className="text-xs leading-relaxed text-fg-2">
                                {state.hint}
                                {!canManage
                                    ? ' Pide a un administrador de tu cuenta que lo haga.'
                                    : ''}
                            </p>
                        ) : null}
                    </div>
                </div>
            </div>

            <dl className="grid grid-cols-2 gap-px border-t border-border bg-border sm:grid-cols-4">
                <Fact
                    label="Eventos (24 h)"
                    value={formatNumber(integration.events24h ?? 0)}
                    hint="recibidos por esta conexión"
                />
                <Fact
                    label="Ubicación en vivo"
                    value={
                        integration.lastLocationAt
                            ? relativeLabel(integration.lastLocationAt)
                            : 'Sin datos'
                    }
                    hint="última posición recibida"
                />
                {integration.fleet ? (
                    <>
                        <Fact
                            label="Unidades"
                            value={formatNumber(integration.fleet.assets)}
                            hint={`${formatNumber(integration.fleet.monitored)} monitoreadas`}
                        />
                        <Fact
                            label="Conductores"
                            value={formatNumber(integration.fleet.drivers)}
                            hint="traídos del proveedor"
                        />
                    </>
                ) : (
                    <Fact
                        label="Catálogo"
                        value={
                            integration.lastSyncAt
                                ? relativeLabel(integration.lastSyncAt)
                                : 'Pendiente'
                        }
                        hint="unidades y conductores al día"
                        className="col-span-2"
                    />
                )}
            </dl>

            {capabilities.length > 0 ? (
                <div className="flex flex-wrap items-center gap-1.5 border-t border-border px-4 py-2.5">
                    <span className="mr-1 text-2xs text-fg-3">Qué trae:</span>
                    {capabilities.map((code) => (
                        <span
                            key={code}
                            title={code}
                            className="inline-flex items-center rounded-sm border border-border bg-surface-2 px-1.5 py-0.5 text-2xs text-fg-2"
                        >
                            {capabilityLabel(code)}
                        </span>
                    ))}
                </div>
            ) : null}

            <TechnicalDetails integration={integration} />
        </article>
    );
}

function Fact({
    label,
    value,
    hint,
    className,
}: {
    label: string;
    value: string;
    hint: string;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex min-w-0 flex-col gap-0.5 bg-surface-1 px-4 py-2.5',
                className,
            )}
        >
            <dt className="truncate text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                {label}
            </dt>
            <dd className="truncate text-sm font-semibold text-fg-1 tabular-nums">
                {value}
            </dd>
            <dd className="truncate text-2xs text-fg-3">{hint}</dd>
        </div>
    );
}

function syncSummary(config: Record<string, unknown> | null): string {
    const sync = (config?.sync ?? {}) as Record<string, unknown>;

    if (sync.enabled === false) {
        return 'Sincronización automática apagada';
    }

    const minutes = Number(sync.catalog_interval_minutes ?? 30);
    const live =
        sync.feed_enabled === false
            ? 'ubicación en vivo apagada'
            : 'ubicación en vivo encendida';

    return `Catálogo cada ${minutes} min · ${live}`;
}

function TechnicalDetails({ integration }: { integration: IntegrationRow }) {
    const [open, setOpen] = useState(false);
    const webhook = integration.webhook;

    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            className="border-t border-border"
        >
            <CollapsibleTrigger className="flex w-full items-center gap-1.5 px-4 py-2.5 text-left text-xs font-medium text-fg-3 outline-none hover:text-fg-1 focus-visible:text-fg-1">
                <ChevronDown
                    size={13}
                    className={cn(
                        'transition-transform',
                        open ? 'rotate-0' : '-rotate-90',
                    )}
                    aria-hidden
                />
                Detalles técnicos
                <span className="ml-auto text-2xs font-normal">
                    para soporte
                </span>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <dl className="flex flex-col gap-3 px-4 pb-4 text-xs">
                    <DetailRow label="Método de acceso">
                        {integration.authTypeLabel ?? integration.authType}{' '}
                        <code className="font-mono text-2xs text-fg-3">
                            {integration.authType}
                        </code>
                    </DetailRow>

                    {webhook ? (
                        <DetailRow
                            label="Dirección para avisos instantáneos"
                            help={`Opcional: pégala en ${integration.provider} → Ajustes → Webhooks para que las alertas lleguen al momento, sin esperar la siguiente consulta.`}
                        >
                            <CopyField value={webhook.url} label="Dirección" />
                            <span className="text-2xs text-fg-3">
                                {webhook.lastReceivedAt
                                    ? `Último aviso recibido ${relativeLabel(webhook.lastReceivedAt)} (${formatDateTime(webhook.lastReceivedAt)})`
                                    : 'Todavía no llega ningún aviso a esta dirección'}
                                {webhook.status !== 'active'
                                    ? ' · recepción pausada'
                                    : ''}
                            </span>
                        </DetailRow>
                    ) : null}

                    <DetailRow label="Última sincronización del catálogo">
                        {formatDateTime(integration.lastSyncAt)}
                    </DetailRow>

                    <DetailRow label="Sincronización">
                        {syncSummary(integration.config)}
                    </DetailRow>

                    {integration.lastErrorMessage ? (
                        <DetailRow
                            label={`Último error (${formatDateTime(integration.lastErrorAt)})`}
                        >
                            <code className="rounded-sm bg-surface-2 px-2 py-1.5 font-mono text-2xs break-words whitespace-pre-wrap text-fg-2">
                                {integration.lastErrorMessage}
                            </code>
                        </DetailRow>
                    ) : null}

                    <DetailRow label="Identificador">
                        <code className="font-mono text-2xs text-fg-3">
                            #{integration.id}
                            {integration.providerCode
                                ? ` · ${integration.providerCode}`
                                : ''}
                        </code>
                    </DetailRow>
                </dl>
            </CollapsibleContent>
        </Collapsible>
    );
}

function DetailRow({
    label,
    help,
    children,
}: {
    label: string;
    help?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-1 sm:grid-cols-[200px_1fr] sm:gap-4">
            <dt className="flex flex-col gap-0.5 text-fg-3">
                <span>{label}</span>
            </dt>
            <dd className="flex min-w-0 flex-col gap-1 text-fg-1">
                {children}
                {help ? (
                    <span className="text-2xs leading-relaxed text-fg-3">
                        {help}
                    </span>
                ) : null}
            </dd>
        </div>
    );
}
