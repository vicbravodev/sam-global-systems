import { StatusBadge } from '@/components/sam/status-badge';
import { subscriptionStatusLabel } from '@/lib/labels';
import type { Tone, ToneLabel } from '@/lib/tone';

/**
 * Etapa del alta de un cliente (la calcula `TenantController::onboardingStage`):
 * dueño → integración → unidades vigiladas → operando.
 */
export type OnboardingStage =
    | 'owner_pending'
    | 'integration_pending'
    | 'assets_pending'
    | 'operating';

const STAGE: Record<OnboardingStage, ToneLabel> = {
    owner_pending: { label: 'Esperando al responsable', tone: 'warn' },
    integration_pending: { label: 'Sin proveedor conectado', tone: 'warn' },
    assets_pending: { label: 'Sin unidades vigiladas', tone: 'info' },
    operating: { label: 'Operando', tone: 'ok' },
};

export function StagePill({ stage }: { stage: OnboardingStage }) {
    return <StatusBadge size="sm" dot {...STAGE[stage]} />;
}

const SUBSCRIPTION_TONE: Record<string, Tone> = {
    active: 'ok',
    trialing: 'info',
    past_due: 'critical',
    unpaid: 'critical',
    suspended: 'warn',
    canceled: 'neutral',
    cancelled: 'neutral',
    incomplete: 'warn',
};

export function SubscriptionPill({ status }: { status: string | null }) {
    if (!status) {
        return <StatusBadge size="sm" tone="neutral" label="Sin suscripción" />;
    }

    return (
        <StatusBadge
            size="sm"
            tone={SUBSCRIPTION_TONE[status] ?? 'neutral'}
            label={subscriptionStatusLabel(status)}
        />
    );
}
