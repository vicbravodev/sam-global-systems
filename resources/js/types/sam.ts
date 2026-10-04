import type { IncidentStatus, Severity } from '@/components/sam';

export type { Severity, IncidentStatus };

export type IntegrationHealth = 'ok' | 'warn' | 'down' | 'unknown';

export type AiDecision = 'incident' | 'escalate' | 'info' | 'discard';

export interface MockAssignee {
    id: number;
    name: string;
    initials: string;
}

export interface MockIncident {
    /** Referencia visible por tenant (`INC-00036`). */
    id: string;
    incidentId: number;
    /** Número secuencial del incidente dentro del tenant. */
    number: number | null;
    title: string;
    severity: Severity;
    status: IncidentStatus;
    /** Server-rendered status string (IncidentStatusPresenter). */
    statusLabel: string;
    provider: string;
    asset: string;
    driver: string;
    assignee: MockAssignee | null;
    /** Monitorista que tiene tomado el incidente (toma humana), si alguno. */
    claimedBy: MockAssignee | null;
    claimedAt: string | null;
    slaSeconds: number;
    slaTotal: number;
    ageMin: number;
    eventType: string;
    location: string;
    /** Evaluación del agente sustituto (`null-agent`): no es un veredicto. */
    aiPlaceholder: boolean;
    /** Null sin evaluación real de IA. */
    aiConfidence: number | null;
    aiDecision: AiDecision;
    aiReason: string;
    realtime?: boolean;
}

export interface MockIntegration {
    name: string;
    key: string;
    health: IntegrationHealth;
    events24h: number;
    lastSync: string;
}

// ---- Integrations management page ----

export type TenantIntegrationStatus =
    | 'active'
    | 'inactive'
    | 'error'
    | 'pending';

/** Signature health of the webhook (`WebhookEndpoint::signatureHealth()`). */
export type WebhookSignatureHealth =
    | 'pending_secret'
    | 'rejecting'
    | 'waiting'
    | 'ok';

export interface IntegrationWebhook {
    url: string;
    status: string;
    lastReceivedAt: string | null;
    /** The Secret Key itself never travels: only whether it is set and when. */
    secretConfigured: boolean;
    secretConfiguredAt: string | null;
    health: WebhookSignatureHealth;
    lastValidReceivedAt: string | null;
    lastRejectedAt: string | null;
    lastRejectionReason: string | null;
    /** `manual`: el cliente pegó la Secret Key; `automatic`: SAM la creó. */
    setupMode: 'manual' | 'automatic';
    setupStatus:
        | 'pending'
        | 'provisioned'
        | 'missing_permissions'
        | 'failed'
        | null;
    setupError: string | null;
    provisionedAt: string | null;
}

export interface IntegrationRow {
    id: number;
    name: string;
    provider: string;
    providerCode: string;
    status: TenantIntegrationStatus;
    health: IntegrationHealth;
    authType: string;
    config: Record<string, unknown> | null;
    lastSyncAt: string | null;
    lastErrorAt: string | null;
    lastErrorMessage: string | null;
    webhook: IntegrationWebhook | null;
    /** The user may manage this integration (Policy `update`). */
    canUpdate?: boolean;
    /** Human label of the auth method ("Clave de API"). */
    authTypeLabel?: string;
    /** Provider capability codes (gps, diagnostics, driver_behavior…). */
    capabilities?: string[];
    connectedAt?: string | null;
    lastLocationAt?: string | null;
    /** Classified `lastErrorMessage` (IntegrationProblem enum). */
    problem?: IntegrationProblemKind | null;
    /** Events received through this integration in the last 24 h. */
    events24h?: number;
    /** Units/drivers brought by the provider; null when shared by several integrations. */
    fleet?: IntegrationFleet | null;
}

export type IntegrationProblemKind =
    | 'credentials'
    | 'rate_limited'
    | 'unreachable'
    | 'provider'
    | 'unknown';

export interface IntegrationFleet {
    assets: number;
    monitored: number;
    drivers: number;
}

export interface IntegrationsSummary {
    total: number;
    working: number;
    attention: number;
    pending: number;
    inactive: number;
    events24h: number;
    assets: number;
    monitored: number;
    drivers: number;
}

export interface IntegrationProviderOption {
    id: number;
    code: string;
    name: string;
    type: string;
    capabilities: string[];
}

export interface AuthTypeOption {
    value: string;
    label: string;
}

export interface MockStreamEvent {
    ts: string;
    provider: string;
    type: string;
    asset: string;
    decision: AiDecision;
    severity: Severity | null;
}

// ---- Inbox UI state types ----

export type InboxLayout = 'table' | 'grouped' | 'stream';
export type InboxDensity = 'compact' | 'comfortable' | 'relaxed';
export type InboxTab =
    | 'open'
    | 'mine'
    | 'unassigned'
    | 'sla'
    | 'all'
    | 'discarded';

// ---- Inbox filters & action option lists (server-provided) ----

export interface InboxFilters {
    q: string | null;
    severity: string | null;
    status: string | null;
    provider: string | null;
    shift: string | null;
}

export interface InboxFilterOption {
    value: string;
    label: string;
}

export interface InboxFilterOptions {
    severities: InboxFilterOption[];
    statuses: InboxFilterOption[];
    providers: string[];
    shifts: InboxFilterOption[];
}

export interface InboxMember {
    id: number;
    name: string;
}

export interface ReclassifyOption {
    id: number;
    code: string;
    name: string;
}

export interface ReclassifyOptions {
    types: ReclassifyOption[];
    priorities: ReclassifyOption[];
}

// ---- Nav badges (sidebar) ----

export interface NavBadges {
    inbox: number;
}

/** Secciones del workspace que el usuario puede abrir (HandleInertiaRequests::navPermissions). */
export interface NavPermissions {
    incidents: boolean;
    events: boolean;
    drivers: boolean;
    /** Monitoreo HOS: feature `hos_monitoring` + `drivers.view`. */
    hos: boolean;
    /** Sección HOS de la configuración: feature + `config.view`. */
    hosConfig: boolean;
    rules: boolean;
    automation: boolean;
    analytics: boolean;
    integrations: boolean;
    notifications: boolean;
    audit: boolean;
    billing: boolean;
    tenantConfig: boolean;
    roles: boolean;
}

// ---- Timeline entries for detail panel ----

export type TimelineEntryType =
    | 'system'
    | 'webhook'
    | 'ai'
    | 'user'
    | 'critical'
    | 'assign'
    | 'comment'
    | 'media'
    | 'sla'
    | 'resolved';

export interface IncidentTimelineEntry {
    type: TimelineEntryType;
    /** Valor crudo del enum de backend (p.ej. `media_assessed`). */
    entryType: string | null;
    actor: string;
    text: string;
    tsIso: string | null;
    sub?: string | null;
    /** Solo entradas `media_assessed`: veredicto crudo + confianza. */
    meta?: { result: string | null; confidence: number | null } | null;
}

// ---- Related incident link ----

export interface RelatedIncidentLink {
    tsIso: string | null;
    eventId: number;
    eventType: string;
    asset: string;
    relationType: string | null;
    severity: Severity | null;
}

// ---- Comment ----

export interface IncidentComment {
    authorInitials: string;
    authorName: string;
    visibility: 'internal' | 'tenant' | 'audit';
    body: string;
    relativeTime: string;
}

// ---- Evidence item ----

export interface IncidentEvidenceItem {
    label: string;
    sub: string;
    type: 'chart' | 'video' | 'map' | 'payload';
    fileUrl: string | null;
}

// ---- Resolution ----

export interface IncidentResolutionInfo {
    code: string | null;
    summary: string | null;
    rootCause: string | null;
    resolvedAt: string | null;
}

// ---- Aggregated visual verdict (mediaSummary) ----

export interface IncidentMediaThumbnail {
    id: number;
    url: string | null;
    mediaType: string | null;
}

export interface IncidentMediaSummary {
    total: number;
    images: number;
    clips: number;
    assessed: number;
    confirms: number;
    contradicts: number;
    inconclusive: number;
    lowQuality: number;
    unavailable: number;
    pendingRequest: boolean;
    thumbnails: IncidentMediaThumbnail[];
}

// ---- Full incident detail (extends MockIncident) ----

export interface IncidentDetail extends MockIncident {
    aiEvaluationId: number | null;
    model: string;
    latencyMs: number;
    summary: string | null;
    openedAt: string | null;
    slaDueAt: string | null;
    eventOccurredAt: string | null;
    aiRiskScore: number | null;
    aiMode: string | null;
    aiEvaluatedAt: string | null;
    aiReasoningSteps: string[];
    /** Veredicto humano sobre la evaluación de IA (human-in-the-loop). */
    aiOperatorVerdict?: 'confirmed' | 'false_positive' | null;
    aiOperatorVerdictLabel?: string | null;
    aiOperatorVerdictAt?: string | null;
    resolution: IncidentResolutionInfo | null;
    /** Presente en el payload del panel (JSON) y de la página completa. */
    mediaSummary?: IncidentMediaSummary | null;
    timeline: IncidentTimelineEntry[];
    relatedLinks: RelatedIncidentLink[];
    comments: IncidentComment[];
    evidence: IncidentEvidenceItem[];
    operationalContext: {
        weather: string;
        traffic: string;
        driverRisk: number;
        geofenceStatus: string;
        drivingHours: string;
    };
}

// ---- Inbox mock data (full dataset for the incidents page) ----

export interface InboxMockData {
    user: { name: string; initials: string; role: string };
    tenant: { slug: string; name: string; logoColor: string };
    navBadges: NavBadges;
    incidents: IncidentDetail[];
    integrations: MockIntegration[];
    stream: MockStreamEvent[];
}

// ---- Roles & team members (settings/roles page) ----

export interface RolePermissionOption {
    code: string;
    name: string;
    description: string | null;
}

export interface RoleRow {
    id: number;
    name: string;
    code: string;
    description: string | null;
    isSystem: boolean;
    /** El usuario puede editar/borrar este rol (sólo roles propios del tenant). */
    editable: boolean;
    permissions: string[];
}

export interface TeamMemberRow {
    id: number;
    userName: string;
    userEmail: string;
    roleCode: string | null;
    roleName: string | null;
    legacyRole: string | null;
    /** Propietario o uno mismo: su rol no se cambia desde aquí. */
    locked: boolean;
}

// ---- Incident full-page detail (F9) ----

export interface IncidentMediaItem {
    id: number;
    mediaType: string | null;
    mimeType: string | null;
    url: string | null;
    thumbnailUrl: string | null;
    /** Frames extraídos de este clip: la IA los evalúa en su nombre. */
    frameIds?: number[];
    durationSeconds: number | null;
    sizeBytes: number | null;
    capturedAt: string | null;
    availabilityStatus: string | null;
    /** Foto que la cámara tomó por su cuenta cerca del evento (no es el evento). */
    context: boolean;
    /** `road` | `driver` | null. */
    camera: string | null;
    /** Segundos entre la captura y el evento (negativo = antes). */
    offsetSeconds: number | null;
}

export interface IncidentMediaAssessment {
    id: number;
    mediaContextId: number;
    result: string | null;
    confidenceScore: number | null;
    summary: string | null;
    assessmentType: string | null;
    modelUsed: string | null;
    assessedAt: string | null;
}

export interface IncidentMediaRequestSummary {
    id: number;
    status: string | null;
    requestType: string | null;
    requestedAt: string | null;
}

export interface PriorIncidentSummary {
    incidentId: number;
    /** Referencia visible (`INC-00036`). */
    reference: string;
    title: string;
    status: string | null;
    statusLabel: string;
    severity: string | null;
    openedAt: string | null;
    relationType: string | null;
    confidenceScore: number | null;
}

/** Si todavía se puede pedir video al dispositivo (ventana de retención). */
export interface IncidentMediaRetrieval {
    available: boolean;
    reason: string | null;
    maxAgeHours: number;
}

export interface IncidentVerificationCall {
    id: number;
    attempt: number;
    status: string | null;
    outcome: string | null;
    phone: string | null;
    placedAt: string | null;
    respondedAt: string | null;
}

export interface IncidentNotificationSummary {
    id: number;
    subject: string;
    createdAt: string | null;
    deliveries: number;
    delivered: number;
    failed: number;
}

export interface IncidentCommunications {
    verificationCalls: IncidentVerificationCall[];
    notifications: IncidentNotificationSummary[];
}

export interface IncidentShowProps {
    incident: IncidentDetail;
    media: IncidentMediaItem[];
    mediaAssessments: IncidentMediaAssessment[];
    mediaRequests: IncidentMediaRequestSummary[];
    mediaRetrieval: IncidentMediaRetrieval;
    communications: IncidentCommunications;
    priorIncidents: PriorIncidentSummary[];
    members: InboxMember[];
    reclassifyOptions: ReclassifyOptions;
}

/** Acciones sobre incidentes que el rol permite (IncidentInboxController::abilities). */
export interface IncidentAbilities {
    manage: boolean;
    resolve: boolean;
    close: boolean;
    requestMedia: boolean;
    reevaluate: boolean;
}
