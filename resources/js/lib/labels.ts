/**
 * Etiquetas humanas (es-MX) para códigos de catálogo que llegan crudos del
 * backend. Única fuente para el frontend: no declarar mapas sueltos en las
 * páginas. Cuando el backend ya envía un `label`/`name` en español, usar ése;
 * estos mapas cubren los códigos que viajan sin etiqueta y los catálogos
 * sembrados en inglés.
 */

/** "panic_button" → "Panic button" como último recurso para códigos desconocidos. */
export function humanizeCode(code: string | null | undefined): string {
    if (!code) {
        return '—';
    }

    const text = code
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/[._-]+/g, ' ')
        .trim()
        .toLowerCase();

    return text.charAt(0).toUpperCase() + text.slice(1);
}

function lookup(
    map: Record<string, string>,
    code: string | null | undefined,
    fallback?: string | null,
): string {
    if (!code) {
        return fallback ?? '—';
    }

    return (
        map[code] ?? map[code.toLowerCase()] ?? fallback ?? humanizeCode(code)
    );
}

// ── Prioridad / severidad ────────────────────────────────────────────────

export const PRIORITY_LABELS: Record<string, string> = {
    critical: 'Crítica',
    high: 'Alta',
    medium: 'Media',
    low: 'Baja',
    info: 'Info',
};

export function priorityLabel(code: string | null | undefined): string {
    return lookup(PRIORITY_LABELS, code);
}

// ── Tipos de evento e incidente ──────────────────────────────────────────

export const EVENT_TYPE_LABELS: Record<string, string> = {
    panic_button: 'Botón de pánico',
    collision: 'Colisión',
    rollover_protection: 'Protección por vuelco',
    harsh_braking: 'Frenado brusco',
    speeding: 'Exceso de velocidad',
    severe_speeding: 'Exceso de velocidad severo',
    driver_fatigue: 'Fatiga del conductor',
    driver_distraction: 'Distracción del conductor',
    forward_collision_warning: 'Aviso de colisión frontal',
    harsh_acceleration: 'Aceleración brusca',
    harsh_turn: 'Giro brusco',
    lane_departure: 'Salida de carril',
    following_distance: 'Distancia de seguimiento',
    near_collision: 'Casi colisión',
    aggressive_driving: 'Conducción agresiva',
    rolling_stop: 'Alto incompleto',
    ran_red_light: 'Se pasó el semáforo en rojo',
    mobile_usage: 'Uso de móvil',
    yaw_control: 'Control de derrape',
    reversing: 'Marcha atrás',
    u_turn: 'Vuelta en U',
    did_not_yield: 'No cedió el paso',
    railroad_crossing_violation: 'Violación de cruce ferroviario',
    other_violation: 'Otra infracción',
    camera_obstructed: 'Cámara obstruida',
    tampering: 'Manipulación del equipo',
    no_seatbelt: 'Sin cinturón de seguridad',
    hos_violation: 'Violación de horas de servicio',
    smoking_drinking: 'Fumar o beber',
    policy_violation: 'Violación de política',
    unauthorized_passenger: 'Pasajero no autorizado',
    geofence_exit: 'Salida de geocerca',
    geofence_entry: 'Entrada a geocerca',
    vehicle_idle: 'Vehículo en ralentí',
    unsafe_parking: 'Estacionamiento inseguro',
    driving_context: 'Contexto de conducción',
    defensive_driving: 'Conducción defensiva',
    device_offline: 'Dispositivo sin conexión',
    after_hours_movement: 'Movimiento fuera de horario',
    suspicious_stop: 'Parada sospechosa',
    unmapped: 'Sin mapear',
    // Tipos crudos de proveedor (Samsara) que pueden llegar sin normalizar.
    maxspeed: 'Exceso de velocidad',
    'panic button': 'Botón de pánico',
    // Tipos de incidente.
    panic_emergency: 'Emergencia de pánico',
    route_deviation: 'Desvío de ruta',
    geofence_breach: 'Violación de geocerca',
    emergency_alert: 'Alerta de emergencia',
    safety_violation: 'Violación de seguridad',
    compliance_violation: 'Violación de cumplimiento',
    operational_alert: 'Alerta operativa',
    other: 'Otro',
};

export function eventTypeLabel(
    code: string | null | undefined,
    fallback?: string | null,
): string {
    return lookup(EVENT_TYPE_LABELS, code, fallback);
}

// ── Acciones automáticas / protocolos ────────────────────────────────────

export const ACTION_LABELS: Record<string, string> = {
    send_email: 'Enviar correo',
    send_whatsapp: 'Enviar WhatsApp',
    send_sms: 'Enviar SMS',
    send_push: 'Notificación push',
    create_ticket: 'Crear ticket',
    assign_incident: 'Asignar incidente',
    escalate: 'Escalar',
    update_asset_state: 'Actualizar estado del activo',
    request_human_review: 'Pedir revisión humana',
    call_webhook: 'Llamar webhook',
    trigger_emergency_protocol: 'Activar protocolo de emergencia',
    place_verification_call: 'Llamada de verificación',
    notify: 'Notificar',
};

export function actionLabel(code: string | null | undefined): string {
    return lookup(ACTION_LABELS, code);
}

// ── Resultado de decisión ────────────────────────────────────────────────

export const DECISION_OUTCOME_LABELS: Record<string, string> = {
    IGNORE: 'Ignorar',
    LOG_ONLY: 'Solo registrar',
    ALERT: 'Alerta',
    INCIDENT: 'Crear incidente',
    ESCALATE: 'Escalar',
    REQUIRE_HUMAN_REVIEW: 'Revisión humana',
};

export function decisionOutcomeLabel(code: string | null | undefined): string {
    if (!code) {
        return '—';
    }

    return DECISION_OUTCOME_LABELS[code.toUpperCase()] ?? humanizeCode(code);
}

// ── Activos ──────────────────────────────────────────────────────────────

export const ASSET_TYPE_LABELS: Record<string, string> = {
    vehicle: 'Vehículo',
    trailer: 'Remolque',
    camera: 'Cámara',
    gps_device: 'Dispositivo GPS',
    sensor: 'Sensor',
    equipment: 'Equipo',
};

/** Acepta el código o el nombre sembrado en inglés ("Vehicle"). */
export function assetTypeLabel(
    code: string | null | undefined,
    fallback?: string | null,
): string {
    const key = code?.toLowerCase().replace(/\s+/g, '_');

    return lookup(ASSET_TYPE_LABELS, key, fallback);
}

export const CONNECTIVITY_LABELS: Record<string, string> = {
    online: 'En línea',
    offline: 'Sin conexión',
    stale: 'Sin señal reciente',
    unknown: 'Desconocido',
};

export function connectivityLabel(code: string | null | undefined): string {
    return lookup(CONNECTIVITY_LABELS, code);
}

export const SOURCE_LABELS: Record<string, string> = {
    provider: 'Proveedor',
    manual: 'Manual',
    system: 'Sistema',
    webhook: 'Webhook',
    poller: 'Sincronización',
    sync: 'Sincronización',
    rule: 'Regla',
    ai: 'IA',
};

export function sourceLabel(code: string | null | undefined): string {
    return lookup(SOURCE_LABELS, code);
}

// ── Equipo y roles ───────────────────────────────────────────────────────

export const TEAM_ROLE_LABELS: Record<string, string> = {
    owner: 'Propietario',
    admin: 'Administrador',
    member: 'Miembro',
};

export function teamRoleLabel(code: string | null | undefined): string {
    return lookup(TEAM_ROLE_LABELS, code);
}

// ── Planes y facturación ─────────────────────────────────────────────────

export const PLAN_LABELS: Record<string, string> = {
    starter: 'Starter',
    pro: 'Pro',
    enterprise: 'Enterprise',
    default_plan: 'Incluido en el plan',
    plan: 'Incluido en el plan',
    promo: 'Promoción',
    manual: 'Ajuste manual',
    override: 'Ajuste manual',
    trial: 'Prueba',
};

export function planLabel(code: string | null | undefined): string {
    return lookup(PLAN_LABELS, code);
}

export const SUBSCRIPTION_STATUS_LABELS: Record<string, string> = {
    active: 'Activa',
    trialing: 'En prueba',
    past_due: 'Pago vencido',
    suspended: 'Suspendida',
    canceled: 'Cancelada',
    cancelled: 'Cancelada',
    incomplete: 'Incompleta',
    unpaid: 'Sin pagar',
};

export function subscriptionStatusLabel(
    code: string | null | undefined,
): string {
    return lookup(SUBSCRIPTION_STATUS_LABELS, code);
}

export const BILLING_CYCLE_LABELS: Record<string, string> = {
    monthly: 'Mensual',
    yearly: 'Anual',
    annual: 'Anual',
    quarterly: 'Trimestral',
};

export function billingCycleLabel(code: string | null | undefined): string {
    return lookup(BILLING_CYCLE_LABELS, code);
}

export const INVOICE_STATUS_LABELS: Record<string, string> = {
    draft: 'Borrador',
    finalized: 'Finalizada',
    invoiced: 'Por pagar',
    paid: 'Pagada',
    disputed: 'En disputa',
    void: 'Anulada',
};

export function invoiceStatusLabel(code: string | null | undefined): string {
    return lookup(INVOICE_STATUS_LABELS, code);
}

export const METER_LABELS: Record<string, string> = {
    monitored_assets: 'Activos monitoreados',
    active_cameras: 'Cámaras activas',
    ai_calls: 'Evaluaciones de IA',
    ai_tokens_in: 'Tokens de IA (entrada)',
    ai_tokens_out: 'Tokens de IA (salida)',
    copilot_queries: 'Consultas a SAM Copilot',
    generated_reports: 'Reportes generados',
    ingested_events: 'Eventos recibidos',
    voice_calls: 'Llamadas de verificación',
    media_requests: 'Solicitudes de video',
    incident_workflows: 'Flujos de incidente',
    automation_actions: 'Acciones automáticas',
    outbound_notifications: 'Notificaciones enviadas',
    sms_messages: 'Mensajes SMS',
    whatsapp_messages: 'Mensajes de WhatsApp',
    voice_notification_calls: 'Llamadas de aviso',
    messaging_cost_micros: 'Mensajería y llamadas (Twilio)',
    otp_sms_sent: 'SMS de verificación (OTP)',
};

export function meterLabel(
    code: string | null | undefined,
    fallback?: string | null,
): string {
    return lookup(METER_LABELS, code, fallback);
}

const METER_UNIT_LABELS: Record<string, [string, string]> = {
    count: ['evento', 'eventos'],
    call: ['llamada', 'llamadas'],
    asset: ['activo', 'activos'],
    camera: ['cámara', 'cámaras'],
    tokens: ['token', 'tokens'],
    token: ['token', 'tokens'],
    query: ['consulta', 'consultas'],
    report: ['reporte', 'reportes'],
    workflow: ['flujo', 'flujos'],
    action: ['acción', 'acciones'],
    message: ['mensaje', 'mensajes'],
    usd_micros: ['USD', 'USD'],
};

/** Unidad de un medidor, en singular o plural según la cantidad. */
export function meterUnitLabel(
    unit: string | null | undefined,
    quantity = 2,
): string {
    if (!unit) {
        return '';
    }

    const forms = METER_UNIT_LABELS[unit];

    if (!forms) {
        return humanizeCode(unit).toLowerCase();
    }

    return quantity === 1 ? forms[0] : forms[1];
}

// ── Notificaciones ───────────────────────────────────────────────────────

export const CHANNEL_LABELS: Record<string, string> = {
    email: 'Correo',
    sms: 'SMS',
    push: 'Push',
    whatsapp: 'WhatsApp',
    web: 'Web',
    slack: 'Slack',
    webhook: 'Webhook',
    voice: 'Llamada de voz',
};

export function channelLabel(code: string | null | undefined): string {
    return lookup(CHANNEL_LABELS, code);
}

// ── Retrasos (automatizaciones / escalación) ─────────────────────────────

/** 300 → "+5 min", 45 → "+45 s", 5400 → "+1 h 30 min"; 0 → "Inmediato". */
export function delayLabel(seconds: number | null | undefined): string {
    const total = Math.max(0, Math.round(Number(seconds ?? 0)));

    if (total === 0) {
        return 'Inmediato';
    }

    if (total < 60) {
        return `+${total} s`;
    }

    const hours = Math.floor(total / 3600);
    const minutes = Math.round((total % 3600) / 60);

    if (hours === 0) {
        return `+${minutes} min`;
    }

    return minutes === 0 ? `+${hours} h` : `+${hours} h ${minutes} min`;
}

// ── Funcionalidades (módulos) del plan y analítica — F1 ─────────────────

export const FEATURE_LABELS: Record<string, string> = {
    ai: 'Evaluación con IA',
    assets: 'Flota',
    audit: 'Auditoría',
    automation: 'Automatizaciones',
    config: 'Configuración del tenant',
    context: 'Contexto y video de eventos',
    copilot: 'SAM Copilot',
    decisions: 'Reglas de decisión',
    drivers: 'Conductores',
    geofences: 'Geocercas',
    incidents: 'Incidentes',
    integrations: 'Integraciones',
    notifications: 'Notificaciones',
    reports: 'Analítica y reportes',
    tenancy: 'Facturación y equipo',
    users: 'Usuarios y roles',
};

export function featureLabel(code: string | null | undefined): string {
    return lookup(FEATURE_LABELS, code);
}

export const REPORT_STATUS_LABELS: Record<string, string> = {
    pending: 'En cola',
    queued: 'En cola',
    running: 'Generando',
    processing: 'Generando',
    completed: 'Listo',
    succeeded: 'Listo',
    failed: 'Falló',
    cancelled: 'Cancelado',
    canceled: 'Cancelado',
    expired: 'Expirado',
};

/** Nombres de KPI sin fila en metric_definitions (los calcula EvaluateAIEffectiveness). */
export const KPI_LABELS: Record<string, string> = {
    incidents_total: 'Incidentes abiertos en el periodo',
    incidents_resolved: 'Incidentes resueltos',
    incidents_open: 'Incidentes aún abiertos',
    incidents_mttr_minutes: 'Tiempo medio de resolución',
    decisions_total: 'Decisiones tomadas',
    decisions_human_review_rate: 'Tasa de revisión humana',
    ai_evaluations_total: 'Evaluaciones de IA',
    ai_total_evaluations: 'Evaluaciones de IA',
    ai_average_confidence: 'Confianza media de la IA',
    ai_accuracy_rate: 'Precisión de la IA',
    ai_false_positive_rate: 'Tasa de falsos positivos',
    ai_real_event_rate: 'Tasa de eventos reales',
    ai_human_override_rate: 'Tasa de corrección humana',
    active_assets: 'Activos monitoreados',
    ingested_events: 'Eventos recibidos',
    ai_calls: 'Llamadas a la IA',
    outbound_notifications: 'Notificaciones enviadas',
    copilot_queries: 'Consultas a SAM Copilot',
    // Claves del resumen del tenant (analytics_snapshots.snapshot_json).
    total_incidents: 'Incidentes',
    resolved_incidents: 'Incidentes resueltos',
    mean_resolution_time_minutes: 'Tiempo medio de resolución',
    active_integrations: 'Integraciones activas',
};

export function kpiLabel(
    code: string | null | undefined,
    fallback?: string | null,
): string {
    if (code && KPI_LABELS[code]) {
        return fallback ?? KPI_LABELS[code];
    }

    return lookup(KPI_LABELS, code, fallback);
}

export function reportStatusLabel(code: string | null | undefined): string {
    return lookup(REPORT_STATUS_LABELS, code);
}

// ── Evidencia multimedia y descripciones de proveedor (F4) ──────────────

export const MEDIA_ROLE_LABELS: Record<string, string> = {
    primary_evidence: 'evidencia principal',
    driver_facing: 'cámara interior',
    road_facing: 'cámara frontal',
    side_facing: 'cámara lateral',
    rear_facing: 'cámara trasera',
};

export function mediaRoleLabel(code: string | null | undefined): string {
    return lookup(MEDIA_ROLE_LABELS, code).toLowerCase();
}

/**
 * Descripción cruda del proveedor ("Panic Button", "MaxSpeed") traducida si
 * es un tipo conocido. Devuelve null cuando la traducción repite el tipo de
 * evento ya mostrado, para no duplicar "Botón de pánico · Botón de pánico".
 */
export function providerDescriptionLabel(
    description: string | null | undefined,
    eventTypeName?: string | null,
): string | null {
    if (!description) {
        return null;
    }

    const key = description.trim().toLowerCase();
    const translated =
        EVENT_TYPE_LABELS[key] ??
        EVENT_TYPE_LABELS[key.replace(/\s+/g, '_')] ??
        null;

    if (translated === null) {
        return description;
    }

    return eventTypeName && translated === eventTypeName ? null : translated;
}
