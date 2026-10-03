import { relativeLabel } from '@/lib/time';
import type { Tone } from '@/lib/tone';
import type {
    IntegrationProblemKind,
    IntegrationRow,
    TenantIntegrationStatus,
} from '@/types/sam';

/**
 * Everything the integrations page says about one connection, in operator
 * language: a short badge, one sentence of what is happening, a hint of what
 * to do and which action fixes it. Derived from the persisted status plus
 * the classified error (`problem`) the controller sends.
 */
export type IntegrationTone = Extract<
    Tone,
    'ok' | 'warn' | 'critical' | 'neutral'
>;

export type IntegrationFix = 'credentials' | 'test';

export interface IntegrationState {
    tone: IntegrationTone;
    badge: string;
    headline: string;
    hint: string | null;
    fix: IntegrationFix;
    /** A secondary warning on an otherwise working connection. */
    warning: string | null;
    /**
     * Samsara's signed webhooks (panics) are not getting in, even though the
     * API connection works: missing or mismatched Secret Key.
     */
    webhookAlert: string | null;
}

const STATUS_BADGE: Record<TenantIntegrationStatus, string> = {
    active: 'Funcionando',
    error: 'Requiere atención',
    pending: 'Pendiente',
    inactive: 'Desactivada',
};

/** Order in the list: what needs a hand first. */
export const STATUS_ORDER: Record<TenantIntegrationStatus, number> = {
    error: 0,
    pending: 1,
    active: 2,
    inactive: 3,
};

function problemCopy(
    problem: IntegrationProblemKind | null | undefined,
    provider: string,
): { headline: string; hint: string; fix: IntegrationFix } {
    switch (problem) {
        case 'credentials':
            return {
                headline: `${provider} rechazó la clave de acceso`,
                hint: `La clave fue revocada, expiró o no tiene los permisos necesarios. Crea una nueva en ${provider} y actualízala aquí.`,
                fix: 'credentials',
            };
        case 'rate_limited':
            return {
                headline: `${provider} está limitando las consultas`,
                hint: 'Recibimos demasiadas respuestas de "espera". Suele resolverse solo en unos minutos; después prueba la conexión.',
                fix: 'test',
            };
        case 'unreachable':
            return {
                headline: `No pudimos comunicarnos con ${provider}`,
                hint: 'Puede ser una caída temporal del proveedor o de la red. Prueba la conexión; si sigue fallando, contacta a soporte.',
                fix: 'test',
            };
        case 'provider':
            return {
                headline: `${provider} respondió con un error`,
                hint: 'El problema está del lado del proveedor. Prueba la conexión en unos minutos.',
                fix: 'test',
            };
        default:
            return {
                headline: 'La conexión dejó de responder',
                hint: 'Prueba la conexión. Si el problema sigue, revisa los detalles técnicos o contacta a soporte.',
                fix: 'test',
            };
    }
}

export function integrationState(
    integration: IntegrationRow,
): IntegrationState {
    const provider = integration.provider;

    switch (integration.status) {
        case 'error': {
            const copy = problemCopy(integration.problem, provider);

            return {
                tone: 'critical',
                badge: STATUS_BADGE.error,
                headline: copy.headline,
                hint: copy.hint,
                fix: copy.fix,
                warning: null,
                webhookAlert: null,
            };
        }
        case 'pending':
            return {
                tone: 'warn',
                badge: STATUS_BADGE.pending,
                headline: 'Aún no recibimos datos de esta conexión',
                hint: `Prueba la conexión para terminar de configurarla. Si la clave es correcta, SAM trae tus unidades y conductores de ${provider} en unos minutos.`,
                fix: 'test',
                warning: null,
                webhookAlert: null,
            };
        case 'inactive':
            return {
                tone: 'neutral',
                badge: STATUS_BADGE.inactive,
                headline: 'Esta conexión está desactivada',
                hint: 'No recibe eventos ni ubicaciones. Prueba la conexión para volver a activarla.',
                fix: 'test',
                warning: null,
                webhookAlert: null,
            };
        default: {
            const synced = integration.lastSyncAt
                ? `sincronizó ${relativeLabel(integration.lastSyncAt)}`
                : 'esperando la primera sincronización';
            const warning =
                integration.problem && integration.lastErrorMessage
                    ? `${problemCopy(integration.problem, provider).headline}${
                          integration.lastErrorAt
                              ? ` (${relativeLabel(integration.lastErrorAt)})`
                              : ''
                      }`
                    : null;
            const webhookAlert = webhookAlertFor(integration);

            if (webhookAlert !== null) {
                return {
                    tone: 'critical',
                    // No "Requiere atención": ese badge es del estado `error`
                    // y el contador de Atención no incluye este caso.
                    badge: 'Pánicos sin recibir',
                    headline: 'Los pánicos de Samsara no están entrando',
                    hint: null,
                    fix:
                        integration.problem === 'credentials'
                            ? 'credentials'
                            : 'test',
                    warning,
                    webhookAlert,
                };
            }

            return {
                tone: warning ? 'warn' : 'ok',
                badge: warning ? 'Funcionando con avisos' : STATUS_BADGE.active,
                headline: `Funcionando · ${synced}`,
                hint: null,
                fix:
                    integration.problem === 'credentials'
                        ? 'credentials'
                        : 'test',
                warning,
                webhookAlert: null,
            };
        }
    }
}

/**
 * Bucket for the pulse strip and its filters: a working Samsara connection
 * whose panics are blocked counts as needing attention, not as working
 * (mirrors `IntegrationPageController::summary()`).
 */
export function summaryStatus(
    integration: IntegrationRow,
): TenantIntegrationStatus {
    return integration.status === 'active' &&
        webhookAlertFor(integration) !== null
        ? 'error'
        : integration.status;
}

/** Panics only reach SAM through Samsara's signed webhook. */
function webhookAlertFor(integration: IntegrationRow): string | null {
    if (integration.providerCode !== 'samsara' || !integration.webhook) {
        return null;
    }

    switch (integration.webhook.health) {
        case 'pending_secret':
            return 'Falta pegar la Secret Key de los avisos instantáneos.';
        case 'rejecting':
            return 'Samsara los está enviando, pero la Secret Key guardada no coincide.';
        default:
            return null;
    }
}

const CAPABILITY_LABELS: Record<string, string> = {
    gps: 'Ubicación en vivo',
    location: 'Ubicación en vivo',
    diagnostics: 'Diagnóstico del motor',
    driver_behavior: 'Eventos de seguridad',
    safety: 'Eventos de seguridad',
    video: 'Cámaras',
    camera: 'Cámaras',
    cameras: 'Cámaras',
    media: 'Fotos y video',
    fuel: 'Combustible',
    temperature: 'Temperatura',
    hos: 'Horas de servicio',
    geofencing: 'Geocercas',
};

/** Human name of a provider capability code; unknown codes are prettified. */
export function capabilityLabel(code: string): string {
    const known = CAPABILITY_LABELS[code];

    if (known) {
        return known;
    }

    const text = code.replace(/[_-]+/g, ' ').trim();

    return text.charAt(0).toUpperCase() + text.slice(1);
}

/** Two-letter monogram for a provider tile ("Samsara" → "Sa"). */
export function providerMonogram(name: string): string {
    const words = name.trim().split(/\s+/).filter(Boolean);

    const [word = '?', second] = words;

    if (second !== undefined) {
        return (word.charAt(0) + second.charAt(0)).toUpperCase();
    }

    return word.charAt(0).toUpperCase() + word.slice(1, 2).toLowerCase();
}
