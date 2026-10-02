import { BillingPill } from '@/components/sam/billing/panel';
import type { BillingTone } from '@/components/sam/billing/panel';
import { subscriptionStatusLabel } from '@/lib/labels';

/**
 * Etapa del alta de un cliente (la calcula `TenantController::onboardingStage`):
 * dueño → integración → unidades vigiladas → operando.
 */
export type OnboardingStage =
    | 'owner_pending'
    | 'integration_pending'
    | 'assets_pending'
    | 'operating';

const STAGE: Record<OnboardingStage, { label: string; tone: BillingTone }> = {
    owner_pending: { label: 'Esperando al responsable', tone: 'warn' },
    integration_pending: { label: 'Sin proveedor conectado', tone: 'warn' },
    assets_pending: { label: 'Sin unidades vigiladas', tone: 'info' },
    operating: { label: 'Operando', tone: 'ok' },
};

export function stageLabel(stage: OnboardingStage): string {
    return STAGE[stage].label;
}

export function StagePill({ stage }: { stage: OnboardingStage }) {
    const { label, tone } = STAGE[stage];

    return (
        <BillingPill tone={tone}>
            <span
                aria-hidden="true"
                className="size-1.5 rounded-full bg-current"
            />
            {label}
        </BillingPill>
    );
}

const SUBSCRIPTION_TONE: Record<string, BillingTone> = {
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
        return <BillingPill tone="neutral">Sin suscripción</BillingPill>;
    }

    return (
        <BillingPill tone={SUBSCRIPTION_TONE[status] ?? 'neutral'}>
            {subscriptionStatusLabel(status)}
        </BillingPill>
    );
}
