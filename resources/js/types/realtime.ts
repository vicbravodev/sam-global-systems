export type RealtimeConnectionState =
    | 'connecting'
    | 'connected'
    | 'disconnected'
    | 'reconnecting'
    | 'failed';

export type AssetLocationUpdatedPayload = {
    asset_id: number;
    latitude: number;
    longitude: number;
    recorded_at: string;
};

/** Current position of one asset, as carried by `fleet.positions_updated`. */
export type FleetPosition = {
    asset_id: number;
    latitude: number;
    longitude: number;
    speed_kph: number | null;
    heading: number | null;
    recorded_at: string;
    moving: boolean | null;
};

/**
 * Every position that moved forward in one telematics feed cycle (~5 s), one
 * message per tenant instead of one per vehicle.
 */
export type FleetPositionsUpdatedPayload = {
    positions: FleetPosition[];
};

export type FleetTelemetryReading = {
    value: number | string;
    unit: string | null;
    recorded_at: string;
};

/** Diagnostics that changed in one feed cycle, keyed by telemetry type. */
export type FleetTelemetryUpdatedPayload = {
    assets: {
        asset_id: number;
        readings: Record<string, FleetTelemetryReading>;
    }[];
};

export type AssetStatusChangedPayload = {
    asset_id: number;
    name: string;
    previous_status: string | null;
    new_status: string;
};

export type AssetMonitoringChangedPayload = {
    asset_id: number;
    name: string;
    previous_state: string;
    new_state: string;
};

export type UsageUpdatedPayload = {
    meter_code: string;
    consumed: number;
    included: number;
    overage: number;
    period_start: string;
    period_end: string;
};

export type AIEvaluationCompletedPayload = {
    evaluation_id: number;
    normalized_event_id: number;
    classification: string;
    priority_level: string;
    confidence_score: number | null;
    risk_score: number | null;
    requires_action: boolean;
};

export type DecisionMadePayload = {
    decision_id: number;
    normalized_event_id: number;
    outcome_code: string;
    priority_level: string;
    requires_human_review: boolean;
    decided_at: string;
};

export type ActionExecutedPayload = {
    action_execution_id: number;
    action_type: string;
    status: string;
    incident_id: number | null;
};

export type IncidentCreatedPayload = {
    incident_id: number;
    title: string;
    priority: string;
    status: string;
    asset_id: number | null;
    driver_id: number | null;
    opened_at: string;
};

export type IncidentUpdatedPayload = {
    incident_id: number;
    status: string;
    priority: string;
    assigned_to: string | null;
    updated_at: string;
};

export type IntegrationStatusChangedPayload = {
    integration_id: number;
    provider_code: string;
    status: 'active' | 'inactive' | 'error' | 'pending';
};

export type ReportReadyPayload = {
    report_execution_id: number;
    report_name: string;
    output_format: string;
    /** Null for scheduled and system runs. */
    requested_by_user_id: number | null;
};

/** In-app notification, sent on the recipient's own `users.{id}` channel. */
export type NotificationPushedPayload = {
    notification_id: number;
    notification_type: string;
    priority: string;
    subject: string | null;
    body_preview: string | null;
    team_id: number | null;
};

export type TeamBroadcastEventMap = {
    'asset.location_updated': AssetLocationUpdatedPayload;
    'fleet.positions_updated': FleetPositionsUpdatedPayload;
    'fleet.telemetry_updated': FleetTelemetryUpdatedPayload;
    'asset.status_changed': AssetStatusChangedPayload;
    'asset.monitoring_changed': AssetMonitoringChangedPayload;
    'usage.updated': UsageUpdatedPayload;
    'ai.evaluation_completed': AIEvaluationCompletedPayload;
    'decisions.decision_made': DecisionMadePayload;
    'action.executed': ActionExecutedPayload;
    'incidents.created': IncidentCreatedPayload;
    'incidents.updated': IncidentUpdatedPayload;
    'integration.status_changed': IntegrationStatusChangedPayload;
    'report.ready': ReportReadyPayload;
};

export type TeamBroadcastEvent = keyof TeamBroadcastEventMap;

export type UserBroadcastEventMap = {
    'notification.pushed': NotificationPushedPayload;
};

export type UserBroadcastEvent = keyof UserBroadcastEventMap;
