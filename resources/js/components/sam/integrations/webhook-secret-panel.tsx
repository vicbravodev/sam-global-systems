import { useHttp } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    CircleDashed,
    KeyRound,
    Loader2,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/format';
import { relativeLabel } from '@/lib/time';
import { cn } from '@/lib/utils';
import { update as updateWebhookSecret } from '@/routes/integrations/webhook-secret';
import type { IntegrationRow, IntegrationWebhook } from '@/types/sam';
import { CopyField } from './copy-field';
import { TONE_SURFACE, TONE_TEXT } from './integration-state';
import type { IntegrationTone } from './integration-state';

interface Props {
    integration: IntegrationRow;
    webhook: IntegrationWebhook;
    teamSlug: string | null;
    onSaved: () => void;
}

interface HealthCopy {
    tone: IntegrationTone;
    icon: LucideIcon;
    badge: string;
    text: string;
}

function healthCopy(webhook: IntegrationWebhook): HealthCopy {
    switch (webhook.health) {
        case 'ok':
            return {
                tone: 'ok',
                icon: CheckCircle2,
                badge: 'Funcionando',
                text: webhook.lastValidReceivedAt
                    ? `Último aviso recibido ${relativeLabel(webhook.lastValidReceivedAt)}.`
                    : 'Los avisos de Samsara llegan correctamente.',
            };
        case 'waiting':
            return {
                tone: 'warn',
                icon: CircleDashed,
                badge: 'Esperando el primer aviso',
                text: 'La Secret Key está guardada. En cuanto Samsara envíe un aviso lo verás aquí.',
            };
        case 'rejecting':
            return {
                tone: 'critical',
                icon: XCircle,
                badge: 'Avisos rechazados',
                text: `Samsara está enviando avisos${webhook.lastRejectedAt ? ` (el último ${relativeLabel(webhook.lastRejectedAt)})` : ''}, pero la Secret Key guardada no coincide. Cópiala otra vez de Samsara y pégala aquí.`,
            };
        default:
            return {
                tone: 'critical',
                icon: AlertTriangle,
                badge: 'Falta la Secret Key',
                text: 'Hasta que la pegues, SAM rechaza todos los avisos de Samsara, incluidos los botones de pánico.',
            };
    }
}

/**
 * Samsara firma cada aviso (webhook) con una Secret Key que genera ella
 * misma; sin copiarla a SAM, los pánicos no entran. Muestra la dirección a
 * pegar en Samsara, el estado de la firma y, a quien puede gestionar la
 * conexión, el campo para guardar la llave. El valor nunca vuelve del
 * servidor ni se re-muestra.
 */
export function WebhookSecretPanel({
    integration,
    webhook,
    teamSlug,
    onSaved,
}: Props) {
    const canUpdate = integration.canUpdate === true;
    const health = healthCopy(webhook);
    const HealthIcon = health.icon;
    const healthy = webhook.health === 'ok' || webhook.health === 'waiting';
    const [editing, setEditing] = useState(false);
    // Sin llave o rechazando: el formulario no se puede esconder.
    const showForm = canUpdate && (editing || !healthy);
    const form = useHttp({ webhook_secret: '' });
    const inputId = `webhook-secret-${integration.id}`;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (teamSlug === null) {
            toast.error('No hay equipo activo.');

            return;
        }

        form.submit(
            updateWebhookSecret({
                current_team: teamSlug,
                integration: integration.id,
            }),
            {
                onSuccess: () => {
                    form.reset();
                    setEditing(false);
                    toast.success(
                        'Secret Key guardada. Los avisos de Samsara ya pueden entrar.',
                    );
                    onSaved();
                },
                onHttpException: (response) => {
                    toast.error(
                        response.status === 403
                            ? 'No tienes permisos para cambiar esta conexión.'
                            : 'No se pudo guardar la Secret Key. Inténtalo de nuevo.',
                    );

                    return false;
                },
                onNetworkError: () => {
                    toast.error('Sin conexión. Inténtalo de nuevo.');

                    return false;
                },
            },
        ).catch(() => undefined);
    };

    return (
        <section
            className="flex flex-col gap-3 border-t border-border px-4 py-3.5"
            aria-label="Avisos instantáneos de Samsara"
        >
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <h4 className="text-sm font-semibold text-fg-1">
                        Avisos instantáneos (pánico y alertas)
                    </h4>
                    <p className="text-xs leading-relaxed text-fg-3">
                        Samsara envía los botones de pánico al momento a esta
                        dirección. Para aceptarlos, SAM necesita la Secret Key
                        que Samsara crea para ella.
                    </p>
                </div>
                {canUpdate && !showForm ? (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setEditing(true)}
                    >
                        <KeyRound size={13} /> Cambiar Secret Key
                    </Button>
                ) : null}
            </div>

            <div
                className={cn(
                    'flex items-start gap-2.5 rounded-md border px-3 py-2.5',
                    TONE_SURFACE[health.tone],
                )}
                role="status"
            >
                <HealthIcon
                    size={15}
                    className={cn('mt-px shrink-0', TONE_TEXT[health.tone])}
                    aria-hidden
                />
                <div className="flex min-w-0 flex-col gap-0.5">
                    <p className="text-sm font-medium text-fg-1">
                        {health.badge}
                    </p>
                    <p className="text-xs leading-relaxed text-fg-2">
                        {health.text}
                        {!canUpdate && !healthy
                            ? ' Pide a un administrador de tu cuenta que la configure.'
                            : ''}
                    </p>
                    <p className="text-2xs text-fg-3">
                        {webhook.secretConfigured
                            ? `Secret Key guardada el ${formatDateTime(webhook.secretConfiguredAt)}`
                            : 'Secret Key sin configurar'}
                        {webhook.status !== 'active'
                            ? ' · recepción pausada'
                            : ''}
                    </p>
                </div>
            </div>

            {showForm ? (
                <form
                    onSubmit={submit}
                    className="flex flex-col gap-3 rounded-md border border-border bg-surface-2 p-3"
                >
                    <ol className="flex list-decimal flex-col gap-1 pl-4 text-xs leading-relaxed text-fg-2 marker:text-fg-3">
                        <li>
                            En Samsara entra a{' '}
                            <strong className="text-fg-1">
                                Ajustes (Settings) → Webhooks
                            </strong>{' '}
                            y crea un webhook nuevo, o abre el de SAM si ya
                            existe.
                        </li>
                        <li>Pega ahí esta dirección y guárdalo:</li>
                    </ol>
                    <CopyField value={webhook.url} label="Dirección" />
                    <ol
                        start={3}
                        className="flex list-decimal flex-col gap-1 pl-4 text-xs leading-relaxed text-fg-2 marker:text-fg-3"
                    >
                        <li>
                            Samsara muestra la{' '}
                            <strong className="text-fg-1">Secret Key</strong> de
                            ese webhook. Cópiala y pégala abajo.
                        </li>
                    </ol>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor={inputId}>Secret Key</Label>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <div className="min-w-0 flex-1">
                                <PasswordInput
                                    id={inputId}
                                    name="webhook_secret"
                                    value={form.data.webhook_secret}
                                    onChange={(e) =>
                                        form.setData(
                                            'webhook_secret',
                                            e.target.value,
                                        )
                                    }
                                    placeholder={
                                        webhook.secretConfigured
                                            ? 'Pega la nueva Secret Key'
                                            : 'Pega aquí la Secret Key'
                                    }
                                    autoComplete="off"
                                    spellCheck={false}
                                    aria-invalid={
                                        form.errors.webhook_secret
                                            ? true
                                            : undefined
                                    }
                                />
                            </div>
                            <div className="flex shrink-0 gap-1.5">
                                <Button
                                    type="submit"
                                    size="sm"
                                    className="h-9"
                                    disabled={
                                        form.processing ||
                                        form.data.webhook_secret.trim() === ''
                                    }
                                >
                                    {form.processing ? (
                                        <Loader2
                                            size={13}
                                            className="animate-spin"
                                        />
                                    ) : null}
                                    {form.processing
                                        ? 'Guardando…'
                                        : 'Guardar Secret Key'}
                                </Button>
                                {healthy ? (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        className="h-9"
                                        onClick={() => {
                                            form.resetAndClearErrors();
                                            setEditing(false);
                                        }}
                                    >
                                        Cancelar
                                    </Button>
                                ) : null}
                            </div>
                        </div>
                        <InputError
                            message={form.errors.webhook_secret}
                            className="text-xs"
                        />
                        <p className="text-2xs text-fg-3">
                            {webhook.secretConfigured
                                ? 'Reemplaza a la anterior. Por seguridad, SAM no vuelve a mostrarla.'
                                : 'Por seguridad, SAM no vuelve a mostrarla después de guardarla.'}
                        </p>
                    </div>
                </form>
            ) : null}
        </section>
    );
}
