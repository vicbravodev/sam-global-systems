import type { OnboardingStage } from '@/components/sam/admin-tenant-status';

export interface TenantRow {
    id: number;
    name: string;
    slug: string;
    membersCount: number;
    owner: { name: string; email: string; pendingAccess: boolean } | null;
    plan: string | null;
    subscriptionStatus: string | null;
    integrationsCount: number;
    monitoredAssets: number;
    stage: OnboardingStage;
    createdAt: string | null;
}

export interface PlanOption {
    code: string;
    name: string;
}

export interface TenantStats {
    total: number;
    operating: number;
    onboarding: number;
    pastDue: number;
    suspended: number;
}

export type QuickFilter = 'operating' | 'onboarding' | 'past_due' | 'suspended';
