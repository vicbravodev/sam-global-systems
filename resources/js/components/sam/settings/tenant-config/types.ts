import type { ConditionFieldDef } from '@/components/sam/condition-builder';

export interface SettingRow {
    id: number;
    key: string;
    group: string | null;
    valueType: string | null;
    value: unknown;
    isActive: boolean;
    version: number;
}

export interface AiProfile {
    profileCode: string | null;
    name: string | null;
    description: string | null;
    riskTolerance: string | null;
    falsePositiveTolerance: string | null;
    automationLevel: string | null;
    mediaStrategy: string | null;
}

export interface NotificationPolicyRow {
    id: number;
    policyCode: string;
    notificationType: string | null;
    priority: string | null;
    allowedChannels: string[];
    fallbackChannels: string[];
    isActive: boolean;
}

export interface EscalationConfigRow {
    id: number;
    escalationType: string;
    triggerConditions: Record<string, unknown>;
    steps: unknown[];
    timeConstraints: Record<string, unknown> | null;
    isActive: boolean;
}

export interface ScheduleProfileRow {
    id: number;
    profileCode: string;
    timezone: string;
    operatingHours: Record<string, unknown>;
    shiftRules: unknown[] | Record<string, unknown> | null;
    afterHoursBehavior: Record<string, unknown> | null;
    isActive: boolean;
}

export interface BrandingProp {
    displayName: string | null;
    primaryColor: string | null;
    secondaryColor: string | null;
    emailSignature: string | null;
    logoUrl: string | null;
}

export interface ChannelRow {
    id: number;
    code: string;
    name: string;
    provider: string | null;
    channelType: string | null;
    isActive: boolean;
    enabledForTeam: boolean;
}

export interface VersionRow {
    id: number;
    version: number;
    createdByType: string | null;
    createdAt: string | null;
    snapshot: Record<string, unknown> | null;
}

export interface Option {
    value: string;
    label: string;
}

export interface RecipientOptions {
    roles: Option[];
    users: (Option & { description?: string })[];
}

export interface TenantConfigProps {
    settings: SettingRow[];
    aiProfile: AiProfile;
    // Sólo se ofrecen las opciones del campo realmente vivo (automation_level):
    // es el único de TenantAIProfile que hoy llega al pipeline.
    aiProfileOptions: {
        automationLevels: Option[];
    };
    notificationPolicies: NotificationPolicyRow[];
    escalationConfigs: EscalationConfigRow[];
    escalationConditionFields: ConditionFieldDef[];
    recipientOptions: RecipientOptions;
    scheduleProfiles: ScheduleProfileRow[];
    versions: VersionRow[];
    channels: ChannelRow[];
    branding: BrandingProp;
    /** Canales que SAM entrega (ProvidedChannels): sólo éstos se ofrecen. */
    channelTypes: Option[];
    notificationTypeOptions: Option[];
    canManageChannels: boolean;
    canManage: boolean;
}
