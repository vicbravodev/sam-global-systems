import { RefreshCw, ShieldCheck, Sparkles, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { postJson } from '@/lib/sam-fetch';
import { submit } from '@/lib/submit';
import { TONE_SURFACE, TONE_TEXT } from '@/lib/tone';
import { cn } from '@/lib/utils';
import {
    provision as provisionWebhook,
    rotate as rotateWebhook,
} from '@/routes/integrations/webhook';
import type { IntegrationRow, IntegrationWebhook } from '@/types/sam';

interface Props {
    integration: IntegrationRow;
    webhook: IntegrationWebhook;
    teamSlug: string | null;
    onSaved: () => void;
}

/** El alta automática dejó el webhook listo en Samsara. */
export function isAutoProvisioned(webhook: IntegrationWebhook): boolean {
    return (
        webhook.setupMode === 'automatic' &&
        webhook.setupStatus === 'provisioned'
    );
}

/**
 * SAM puede crear por su cuenta el webhook y su alerta de pánico en Samsara
 * con la API key del cliente. Muestra cómo quedó ese alta y las acciones:
 * configurar (o reintentar) y rotar la llave. El flujo manual de la Secret
 * Key sigue debajo, en el panel que la contiene.
 */
export function WebhookAutoSetup({
    integration,
    webhook,
    teamSlug,
    onSaved,
}: Props) {
    const [busy, setBusy] = useState<'provision' | 'rotate' | null>(null);
    const canUpdate = integration.canUpdate === true && teamSlug !== null;
    const provisioned = isAutoProvisioned(webhook);
    const route = { current_team: teamSlug ?? '', integration: integration.id };

    const run = async (action: 'provision' | 'rotate') => {
        setBusy(action);
        const result = await submit(
            postJson(
                action === 'provision'
                    ? provisionWebhook.url(route)
                    : rotateWebhook.url(route),
            ),
            action === 'provision'
                ? 'Listo: SAM configuró los avisos de pánico en Samsara.'
                : 'Llave rotada. Los avisos siguen entrando sin cortes.',
            {
                forbiddenMessage:
                    'No tienes permisos para cambiar esta conexión.',
                errorMessage:
                    'Samsara no respondió bien. Inténtalo de nuevo en unos minutos.',
            },
        );
        setBusy(null);

        if (!result.ok) {
            onSaved();
        }
    };

    if (provisioned) {
        return (
            <div
                className={cn(
                    'flex flex-wrap items-start justify-between gap-2 rounded-md border px-3 py-2.5',
                    TONE_SURFACE.ok,
                )}
            >
                <div className="flex min-w-0 items-start gap-2.5">
                    <ShieldCheck
                        size={15}
                        className={cn('mt-px shrink-0', TONE_TEXT.ok)}
                        aria-hidden
                    />
                    <div className="flex min-w-0 flex-col gap-0.5">
                        <p className="text-sm font-medium text-fg-1">
                            Configurado automáticamente por SAM
                        </p>
                        <p className="text-xs leading-relaxed text-fg-2">
                            SAM creó en tu Samsara el webhook y la alerta «SAM –
                            Botón de pánico». No tienes que copiar nada.
                        </p>
                        {webhook.provisionedAt ? (
                            <p className="text-2xs text-fg-3">
                                Desde el {formatDateTime(webhook.provisionedAt)}
                            </p>
                        ) : null}
                    </div>
                </div>
                {canUpdate ? (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={busy !== null}
                        onClick={() => void run('rotate')}
                    >
                        {busy === 'rotate' ? (
                            <Spinner className="size-3.25" />
                        ) : (
                            <RefreshCw size={13} />
                        )}
                        Rotar llave
                    </Button>
                ) : null}
            </div>
        );
    }

    const problem =
        webhook.setupStatus === 'missing_permissions'
            ? 'Tu API key de Samsara no tiene permiso para crear webhooks y alertas. Genera una con «Write Webhooks» y «Write Alerts» y cámbiala en Editar, o configura el webhook a mano abajo.'
            : webhook.setupStatus === 'failed'
              ? 'No pudimos configurarlo en Samsara. Puedes reintentar, o configurar el webhook a mano abajo.'
              : null;

    if (!canUpdate && problem === null) {
        return null;
    }

    return (
        <div
            className={cn(
                'flex flex-wrap items-start justify-between gap-2 rounded-md border px-3 py-2.5',
                problem ? TONE_SURFACE.warn : 'border-border bg-surface-2',
            )}
        >
            <div className="flex min-w-0 items-start gap-2.5">
                {problem ? (
                    <TriangleAlert
                        size={15}
                        className={cn('mt-px shrink-0', TONE_TEXT.warn)}
                        aria-hidden
                    />
                ) : (
                    <Sparkles
                        size={15}
                        className="mt-px shrink-0 text-fg-3"
                        aria-hidden
                    />
                )}
                <div className="flex min-w-0 flex-col gap-0.5">
                    <p className="text-sm font-medium text-fg-1">
                        {problem
                            ? 'Configuración automática pendiente'
                            : 'Deja que SAM lo configure'}
                    </p>
                    <p className="text-xs leading-relaxed text-fg-2">
                        {problem ??
                            'SAM puede crear el webhook y la alerta de pánico en tu Samsara con tu API key, sin copiar la Secret Key.'}
                    </p>
                </div>
            </div>
            {canUpdate ? (
                <Button
                    type="button"
                    size="sm"
                    variant={problem ? 'default' : 'outline'}
                    disabled={busy !== null}
                    onClick={() => void run('provision')}
                >
                    {busy === 'provision' ? (
                        <Spinner className="size-3.25" />
                    ) : (
                        <Sparkles size={13} />
                    )}
                    {webhook.setupStatus === 'failed'
                        ? 'Reintentar'
                        : 'Configurar automáticamente'}
                </Button>
            ) : null}
        </div>
    );
}
