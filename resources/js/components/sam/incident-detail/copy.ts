/** Textos y catálogos propios del detalle de incidente. */

import type { AiDecision } from '@/types/sam';

export const RESOLUTION_LABELS: Record<string, string> = {
    handled_successfully: 'Resuelto correctamente',
    false_positive: 'Falso positivo',
    operator_confirmed_safe: 'Operador confirmó seguro',
    resolved_externally: 'Resuelto externamente',
    escalated_externally: 'Escalado externamente',
    unresolved_closed: 'Cerrado sin resolver',
    duplicate_incident: 'Incidente duplicado',
};

export const EVENT_ROLE_LABELS: Record<string, string> = {
    root_trigger: 'Disparador',
    supporting_event: 'Soporte',
};

export const INCIDENT_RELATION_LABELS: Record<string, string> = {
    same_asset_open_incident: 'Mismo activo',
    same_driver_recent_incident: 'Mismo conductor',
    same_location_cluster: 'Misma zona',
    probable_followup: 'Probable seguimiento',
    duplicate_operational_case: 'Caso duplicado',
    prior_similar_incident: 'Incidente similar previo',
};

export const CALL_STATUS_LABELS: Record<string, string> = {
    pending: 'Pendiente',
    calling: 'Llamando',
    answered: 'Contestada',
    no_answer: 'Sin respuesta',
    failed: 'Fallida',
};

export const CALL_OUTCOME_LABELS: Record<string, string> = {
    confirmed_real: 'Confirmó emergencia',
    confirmed_false: 'Descartó (falsa alarma)',
    no_answer: 'Sin respuesta',
};

export const AI_DECISION_LABELS: Record<AiDecision, string> = {
    incident: 'Incidente confirmado',
    escalate: 'Escalamiento recomendado',
    info: 'Evento informativo',
    discard: 'Descartado',
};

export const OPERATOR_VERDICT_LABELS: Record<
    'confirmed' | 'false_positive',
    string
> = {
    confirmed: 'Confirmado por operador',
    false_positive: 'Falso positivo (operador)',
};

/** Etiquetas en español para el veredicto por media de la IA. */
export const MEDIA_RESULT_LABELS: Record<string, string> = {
    confirms_event: 'Confirma el evento',
    contradicts_event: 'Contradice el evento',
    inconclusive: 'No concluyente',
    low_quality: 'Baja calidad',
    unavailable: 'No disponible',
};
