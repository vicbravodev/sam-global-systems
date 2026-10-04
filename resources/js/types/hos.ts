/** Monitoreo HOS (EE. UU.): configuración (sección de Ajustes) y panel. */

export type HosSituationKey =
    | 'break_due'
    | 'drive_limit'
    | 'shift_limit'
    | 'cycle_limit'
    | 'rest_complete'
    | 'violation';

/** Situaciones que el tenant puede apagar: la infracción siempre se vigila. */
export type HosConfigurableSituation = Exclude<HosSituationKey, 'violation'>;

export type HosUrgencyLevel = 'violation' | 'at_limit' | 'warning' | 'ok';

export interface HosLadderStep {
    afterMinutes: number;
    channels: string[];
    escalate: boolean;
}

export interface HosConfigValues {
    tagIds: string[];
    includedAssetIds: number[];
    excludedAssetIds: number[];
    situations: Record<HosConfigurableSituation, boolean>;
    /** Avisos previos en minutos, mayor primero (sin el 0 del límite). */
    leadMinutes: number[];
    cycleLeadHours: number[];
    restCompleteNudgeMinutes: number[];
    restCompleteExpireMinutes: number;
    ladder: HosLadderStep[];
}

export interface HosAssetOption {
    id: number;
    name: string;
    code: string | null;
    monitored: boolean;
}

export interface HosChannelOption {
    value: string;
    /** Hay canal de plataforma activo y el tenant no lo apagó. */
    available: boolean;
}

export interface HosConfigForm {
    config: HosConfigValues;
    defaults: HosConfigValues;
    assets: HosAssetOption[];
    channels: HosChannelOption[];
    hasIntegration: boolean;
    canManage: boolean;
    minGapMinutes: number;
}

export type HosTagKind = 'vehicle' | 'driver' | 'both' | 'empty';

export interface HosTagOption {
    id: string;
    name: string;
    parentId: string | null;
    parentName: string | null;
    depth: number;
    kind: HosTagKind;
    vehicleCount: number;
    driverCount: number;
}

export interface HosPreview {
    trucks: number;
    drivers: number;
    skipped: Record<string, number>;
    failed: boolean;
    hasIntegration: boolean;
}

export interface HosClocks {
    break: number | null;
    drive: number | null;
    shift: number | null;
    cycle: number | null;
}

export interface HosAssetSummary {
    id: number;
    name: string;
    code: string | null;
}

export interface HosStateSnapshot {
    dutyStatus: string | null;
    statusSince: string | null;
    appDisconnectedSince: string | null;
    observedAt: string;
    stale: boolean;
    asset: HosAssetSummary | null;
    clocks: HosClocks;
    violationSeconds: number;
}

export interface HosNudgeDelivery {
    channel: string | null;
    status: string;
}

export interface HosNudge {
    id: number;
    step: number | null;
    notice: string | null;
    createdAt: string | null;
    deliveries: HosNudgeDelivery[];
}

export interface HosEpisodeEntry {
    id: number;
    situation: HosSituationKey;
    openedAt: string;
    resolvedAt: string | null;
    resolution: string | null;
    ladderStep: number;
    nextNudgeAt: string | null;
    escalatedAt: string | null;
    incident: { id: number; reference: string } | null;
    nudges: HosNudge[];
}

export interface HosDriverPanelData {
    state: HosStateSnapshot | null;
    openEpisodes: HosEpisodeEntry[];
    history: HosEpisodeEntry[];
}

export interface HosFleetEpisode {
    id: number;
    situation: HosSituationKey;
    ladderStep: number;
    escalated: boolean;
}

export interface HosFleetRow {
    driver: { id: number; fullName: string };
    asset: HosAssetSummary | null;
    dutyStatus: string | null;
    appDisconnected: boolean;
    observedAt: string;
    stale: boolean;
    clocks: HosClocks;
    violationSeconds: number;
    urgency: HosUrgencyLevel;
    minRemainingSeconds: number | null;
    openEpisodes: HosFleetEpisode[];
}

export type HosFleetSummary = Record<HosUrgencyLevel, number> & {
    total: number;
    /** Filas sin lectura reciente: listadas, fuera de los niveles. */
    stale: number;
};

export interface HosFleetData {
    rows: HosFleetRow[];
    summary: HosFleetSummary;
    /** Lectura más reciente del team a cualquier edad (null si nunca hubo). */
    lastObservedAt: string | null;
}

export interface HosFleetPageProps {
    fleet: HosFleetData;
}
