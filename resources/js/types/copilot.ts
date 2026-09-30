import type { Severity } from '@/components/sam/severity-badge';

export type CopilotIntent =
    | 'asset_report'
    | 'asset_location'
    | 'asset_media'
    | 'engine_stats'
    | 'fuel_report'
    | 'panic_kpis'
    | 'open_incidents'
    | 'driver_ranking'
    | 'fleet_overview'
    | 'asset_ranking'
    | 'event_search'
    | 'asset_timeline'
    | 'general';

export type CopilotChannel = 'page' | 'bubble';

export type Tone = 'critical' | 'high' | 'ok' | null | undefined;

export type MotionState = 'moving' | 'stopped' | 'no_signal';

export interface CopilotLocation {
    latitude: number;
    longitude: number;
    formattedLocation: string | null;
    speed: number | null;
    heading: number | null;
    recordedAt: string;
    mapsUrl: string;
}

export interface AssetBlock {
    type: 'asset';
    asset: {
        id: number;
        code: string | null;
        name: string;
        category: string | null;
        categoryLabel: string | null;
        typeName: string | null;
        status: string;
        statusLabel: string;
        motion: MotionState;
        motionLabel: string;
        provider: string | null;
        driver: { id: number; name: string; href: string } | null;
        location: CopilotLocation | null;
        lastSignalAt: string | null;
        href: string;
    };
}

export interface LocationBlock extends CopilotLocation {
    type: 'location';
    assetId: number;
    assetLabel: string;
    motion: MotionState;
    motionLabel: string;
    maxSpeed: number | null;
    trail: {
        latitude: number;
        longitude: number;
        speed: number | null;
        recordedAt: string;
    }[];
    href: string;
}

export interface TelemetryBlock {
    type: 'telemetry';
    title: string;
    period: string;
    readings: {
        key: string;
        label: string;
        value: string | number | null;
        unit: string | null;
        recordedAt: string;
    }[];
    stats: {
        label: string;
        value: string | number;
        unit?: string | null;
        hint?: string | null;
        tone?: Tone;
    }[];
    series: { label: string; points: { t: string; v: number }[] };
}

export interface FuelBlock {
    type: 'fuel';
    assetId: number;
    assetLabel: string;
    period: string;
    current: number;
    unit: string;
    recordedAt: string;
    low: boolean;
    consumed: number;
    refuels: { at: string; from: number; to: number }[];
    suddenDrops: { at: string; from: number; to: number }[];
    series: { t: string; v: number }[];
}

export interface MediaItem {
    id: number;
    mediaType: string | null;
    role: string | null;
    roleLabel: string | null;
    url: string | null;
    thumbnailUrl: string | null;
    mimeType: string | null;
    durationSeconds: number | null;
    capturedAt: string | null;
    availability: string | null;
    eventType: string | null;
    eventHref: string | null;
}

export interface MediaBlock {
    type: 'media';
    assetId: number;
    assetLabel: string;
    items: MediaItem[];
    href: string;
}

export interface KpisBlock {
    type: 'kpis';
    items: {
        label: string;
        value: string | number;
        tone?: Tone;
        hint?: string | null;
    }[];
}

export interface BarsBlock {
    type: 'bars';
    title: string;
    total: number;
    tone?: Tone;
    items: { label: string; value: number }[];
    legend: { label: string; value: number }[];
}

export interface IncidentRow {
    id: number;
    /** Referencia visible por tenant (`INC-00036`), si hay incidente. */
    reference?: string | null;
    title: string;
    severity: Severity;
    statusLabel: string;
    assetCode: string | null;
    driverName: string | null;
    openedAt: string | null;
    slaDueAt: string | null;
    slaBreached: boolean;
    href: string;
}

export interface IncidentsBlock {
    type: 'incidents';
    title: string;
    items: IncidentRow[];
}

export interface EventsBlock {
    type: 'events';
    title: string;
    items: {
        id: number;
        title: string;
        severity: Severity;
        assetCode: string | null;
        driverName: string | null;
        occurredAt: string;
        statusLabel: string;
        href: string;
    }[];
}

export interface DriversBlock {
    type: 'drivers';
    items: {
        id: number;
        name: string;
        employeeCode: string | null;
        score: number;
        level: string | null;
        levelLabel: string | null;
        incidents: number;
        harsh: number;
        fatigue: number;
        href: string;
    }[];
}

export interface FleetRow {
    id: number;
    code: string | null;
    name: string;
    category: string | null;
    status: string;
    statusLabel: string;
    motion: MotionState;
    motionLabel: string;
    driverName: string | null;
    location: CopilotLocation | null;
    href: string;
}

export interface AssetsBlock {
    type: 'assets';
    title: string;
    total: number;
    items: FleetRow[];
}

export interface FleetMapBlock {
    type: 'fleet_map';
    points: {
        id: number;
        code: string;
        latitude: number;
        longitude: number;
        motion: MotionState;
        status: string;
    }[];
}

export interface AssetPickerBlock {
    type: 'asset_picker';
    intent: CopilotIntent;
    text: string;
    options: CopilotAssetOption[];
}

export interface RankingBlock {
    type: 'ranking';
    metric: string;
    label: string;
    unit: string;
    items: {
        assetId: number;
        code: string | null;
        name: string;
        value: number;
        outlier: boolean;
        href: string;
    }[];
    average: number;
}

export interface TimelineItem {
    at: string;
    kind: 'event' | 'incident' | 'idle';
    label: string;
    severity?: Severity;
    href?: string;
    minutes?: number;
}

export interface TimelineBlock {
    type: 'timeline';
    items: TimelineItem[];
}

export interface NoticeBlock {
    type: 'notice';
    tone: 'warn' | 'info';
    text: string;
}

export type CopilotBlock =
    | AssetBlock
    | LocationBlock
    | TelemetryBlock
    | FuelBlock
    | MediaBlock
    | KpisBlock
    | BarsBlock
    | IncidentsBlock
    | EventsBlock
    | DriversBlock
    | AssetsBlock
    | FleetMapBlock
    | AssetPickerBlock
    | RankingBlock
    | TimelineBlock
    | NoticeBlock;

export interface CopilotSource {
    kind: 'asset' | 'incident' | 'event' | 'driver';
    id: number;
    label: string;
    href: string;
}

export interface CopilotUsage {
    model: string | null;
    inputTokens: number;
    outputTokens: number;
    cost: number;
    latencyMs: number;
}

/** One tool the turn ran; status/durationMs are absent on answers stored before the agent. */
export interface CopilotToolTrace {
    tool: string;
    label: string;
    status?: 'ok' | 'denied' | 'error';
    durationMs?: number;
}

export interface CopilotMessage {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    intent: CopilotIntent | null;
    intentLabel: string | null;
    blocks: CopilotBlock[];
    tools: CopilotToolTrace[];
    sources: CopilotSource[];
    context: {
        asset_id?: number;
        intent?: string;
        resolved?: { asset_id: number | null; asset_code?: string | null };
        facts_digest?: string;
        followups?: string[];
        mode?: 'agent' | 'deterministic';
        partial?: boolean;
    } | null;
    /** Follow-up questions the agent suggested (empty for user turns). */
    followups: string[];
    /** The answer was cut short (provider failure or client disconnect mid-stream). */
    partial?: boolean;
    usage: CopilotUsage | null;
    feedback: -1 | 1 | null;
    createdAt: string | null;
    /** Client-only: optimistic question not yet confirmed by the server. */
    pending?: boolean;
    /** Client-only: the answer is still being streamed. */
    streaming?: boolean;
    /** Client-only: tools currently running for a streaming answer. */
    activeTools?: { toolCallId: string; label: string }[];
}

export interface CopilotConversation {
    id: number;
    title: string;
    isPinned: boolean;
    messagesCount: number;
    lastMessageAt: string | null;
}

export interface CopilotAssetOption {
    id: number;
    code: string | null;
    name: string;
    category: string | null;
    status?: string;
}

export interface CopilotTemplate {
    intent: CopilotIntent;
    label: string;
    icon: string;
    needsAsset: boolean;
    prompt: string;
}

export interface CopilotQuota {
    used: number;
    included: number | null;
    percent: number | null;
    periodStart: string;
    overage: number;
}

export interface CopilotCatalog {
    conversations: CopilotConversation[];
    assets: CopilotAssetOption[];
    templates: CopilotTemplate[];
    suggestions: { group: string; prompts: string[] }[];
    quota: CopilotQuota;
    engine: { mode: 'llm' | 'grounded'; model: string | null };
    canViewUsage: boolean;
}

export interface CopilotSendHints {
    assetId?: number | null;
    intent?: CopilotIntent | null;
}

export interface CopilotUsageReport {
    range: { from: string; to: string; days: number };
    totals: {
        queries: number;
        activeUsers: number;
        inputTokens: number;
        outputTokens: number;
        cost: number;
        avgLatencyMs: number;
        avgTokensPerQuery: number;
        satisfaction: number | null;
        rated: number;
    };
    series: { date: string; queries: number; tokens: number }[];
    byIntent: { intent: string | null; label: string; queries: number }[];
    byChannel: Record<string, number>;
    byUser: {
        userId: number;
        name: string;
        email: string | null;
        queries: number;
        tokens: number;
        cost: number;
        lastUsedAt: string | null;
    }[];
    recent: {
        id: number;
        question: string;
        user: string | null;
        intent: string | null;
        channel: string | null;
        model: string | null;
        inputTokens: number;
        outputTokens: number;
        cost: number;
        latencyMs: number;
        feedback: -1 | 1 | null;
        createdAt: string | null;
    }[];
    quota: CopilotQuota;
}
