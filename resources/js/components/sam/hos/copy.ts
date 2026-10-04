/** Textos y catálogos del monitoreo HOS (mismo vocabulario que HosNoticeCopy). */
import type { ToneLabel } from '@/lib/tone';
import type {
    HosConfigurableSituation,
    HosSituationKey,
    HosUrgencyLevel,
} from '@/types/hos';

/** Situaciones que el tenant puede apagar, en el orden de la pantalla. */
export const HOS_SITUATIONS: {
    key: HosConfigurableSituation;
    label: string;
    help: string;
}[] = [
    {
        key: 'break_due',
        label: 'Descanso de 30 min',
        help: 'Avisa antes de que se le acabe el tiempo para tomar su descanso obligatorio de media hora.',
    },
    {
        key: 'drive_limit',
        label: '11 h de manejo',
        help: 'Avisa antes de que se le acaben sus 11 h de manejo del día.',
    },
    {
        key: 'shift_limit',
        label: 'Turno de 14 h',
        help: 'Avisa antes de que termine su turno de 14 h; después ya no puede manejar.',
    },
    {
        key: 'cycle_limit',
        label: 'Ciclo de 70 h',
        help: 'Avisa cuando le quedan pocas horas en su ciclo de 70 h en 8 días.',
    },
    {
        key: 'rest_complete',
        label: 'Descanso cumplido',
        help: 'Le avisa que ya cumplió su descanso y puede retomar su ruta.',
    },
];

export const HOS_SITUATION_LABELS: Record<HosSituationKey, string> = {
    break_due: 'Descanso de 30 min',
    drive_limit: '11 h de manejo',
    shift_limit: 'Turno de 14 h',
    cycle_limit: 'Ciclo de 70 h',
    rest_complete: 'Descanso cumplido',
    violation: 'Infracción',
};

/** Estado del chofer en Samsara (`hosStatusType`). */
export const HOS_DUTY_STATUS: Record<string, ToneLabel> = {
    driving: { label: 'Manejando', tone: 'info' },
    onDuty: { label: 'En turno, sin manejar', tone: 'neutral' },
    yardMove: { label: 'Movimiento en patio', tone: 'neutral' },
    personalConveyance: { label: 'Uso personal', tone: 'neutral' },
    offDuty: { label: 'Fuera de turno', tone: 'ok' },
    sleeperBed: { label: 'En litera', tone: 'ok' },
};

export const HOS_APP_DISCONNECTED: ToneLabel = {
    label: 'App desconectada',
    tone: 'warn',
};

export const HOS_UNKNOWN_STATUS: ToneLabel = {
    label: 'Sin estado',
    tone: 'neutral',
};

export const HOS_URGENCY: Record<HosUrgencyLevel, ToneLabel> = {
    violation: { label: 'Infracción', tone: 'critical' },
    at_limit: { label: 'En el límite', tone: 'high' },
    warning: { label: 'Por llegar al límite', tone: 'warn' },
    ok: { label: 'En regla', tone: 'ok' },
};

export const HOS_RESOLUTION: Record<string, ToneLabel> = {
    corrected: { label: 'Corregido', tone: 'ok' },
    expired: { label: 'Venció sin arrancar', tone: 'neutral' },
    unenrolled: { label: 'Salió del monitoreo', tone: 'neutral' },
};

export const HOS_CLOCK_LABELS = {
    break: 'Para el descanso de 30 min',
    drive: 'Manejo (11 h)',
    shift: 'Turno (14 h)',
    cycle: 'Ciclo (70 h)',
} as const;

/** Qué le dijo SAM (`HosNotice`), para el historial de avisos. */
export const HOS_NOTICE_LABELS: Record<string, string> = {
    break_lead: 'Aviso previo del descanso',
    break_limit: 'Ya le toca su descanso',
    break_insist: 'Insistencia: descanso',
    drive_lead: 'Aviso previo del manejo',
    drive_limit: 'Se acabaron sus 11 h de manejo',
    drive_insist: 'Insistencia: manejo',
    shift_lead: 'Aviso previo del turno',
    shift_limit: 'Se acabó su turno de 14 h',
    shift_insist: 'Insistencia: turno',
    cycle_lead: 'Aviso del ciclo de 70 h',
    rest_complete: 'Ya puede retomar',
    violation: 'Infracción',
};

/** Por qué un chofer de la lectura no entra (ResolveHosEnrollment). */
export const HOS_SKIPPED_LABELS: Record<string, string> = {
    no_vehicle: 'sin tracto asignado',
    driver_unresolved: 'chofer sin registrar en SAM',
    vehicle_unresolved: 'tracto no vigilado o sin registrar',
    excluded: 'excluidos',
    no_match: 'sin etiqueta ni selección',
};
