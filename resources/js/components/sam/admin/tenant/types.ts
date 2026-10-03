import type {
    AssetUsage,
    BillingDefaults,
    BillingTerms,
    InvoiceRow,
    PlanOption,
    SubscriptionInfo,
} from '@/components/sam/admin/tenant/billing';

export interface Tenant {
    id: number;
    name: string;
    slug: string;
    isPersonal: boolean;
    timezone: string | null;
    createdAt: string | null;
    branding: {
        displayName: string | null;
        primaryColor: string | null;
        secondaryColor: string | null;
        logoUrl: string | null;
    };
}

export interface Member {
    id: number;
    name: string;
    email: string;
    role: string;
    pendingAccess: boolean;
}

export interface Feature {
    key: string;
    enabled: boolean;
    source: string;
    limits: Record<string, unknown> | null;
}

export interface UsageRow {
    meter: string;
    meterCode: string | null;
    periodStart: string | null;
    consumed: number;
    included: number;
    overage: number;
    money: { providerCost: number; charged: number } | null;
}

interface SetupStep {
    key: string;
    label: string;
    done: boolean;
    detail: string;
}

export interface Setup {
    steps: SetupStep[];
    completed: number;
    total: number;
}

export interface AdminTenantShowProps {
    tenant: Tenant;
    subscription: SubscriptionInfo | null;
    members: Member[];
    setup: Setup;
    features: Feature[];
    usage: UsageRow[];
    invoices: InvoiceRow[];
    plans: PlanOption[];
    assetUsage: AssetUsage;
    billingTerms: BillingTerms;
    billingDefaults: BillingDefaults;
}

export interface Pending {
    title: string;
    description: string;
    confirmLabel: string;
    tone: 'destructive' | 'default';
    run: () => Promise<void>;
}
