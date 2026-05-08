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

export type AssetStatusChangedPayload = {
    asset_id: number;
    name: string;
    previous_status: string | null;
    new_status: string;
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

export type TeamBroadcastEventMap = {
    'asset.location_updated': AssetLocationUpdatedPayload;
    'asset.status_changed': AssetStatusChangedPayload;
    'usage.updated': UsageUpdatedPayload;
    'ai.evaluation_completed': AIEvaluationCompletedPayload;
    'decisions.decision_made': DecisionMadePayload;
    'action.executed': ActionExecutedPayload;
};

export type TeamBroadcastEvent = keyof TeamBroadcastEventMap;
