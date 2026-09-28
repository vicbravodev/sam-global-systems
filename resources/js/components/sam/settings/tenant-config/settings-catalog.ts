import { formatNumber } from '@/lib/format';
import { humanizeCode } from '@/lib/labels';
import type { SettingRow } from './types';

/** Claves con control propio en Emergencias / Avisos. */
export const MEDIA_AUTO_REQUEST_KEY = 'media.auto_request_on_critical';
export const PANIC_AUTO_CLOSE_KEY = 'panic.auto_close_on_external_resolution';
export const LIVE_LOCATION_KEY = 'context.live_location_staleness_seconds';
export const MIN_SEVERITY_KEY = 'notifications.out_of_band_min_severity';

/** Default del backend (FetchLiveLocationForEvent::DEFAULT_STALENESS_SECONDS). */
export const LIVE_LOCATION_DEFAULT_SECONDS = 60;

export const DEDICATED_KEYS = new Set([
    MEDIA_AUTO_REQUEST_KEY,
    PANIC_AUTO_CLOSE_KEY,
    LIVE_LOCATION_KEY,
    MIN_SEVERITY_KEY,
]);

export type SettingUnit = 'seconds' | 'minutes' | 'hours' | 'days' | 'count';

export interface KnownSetting {
    label: string;
    help: string;
    /** Tema bajo el que se agrupa en "Avanzado". */
    topic: SettingTopic;
    unit?: SettingUnit;
    /** Mínimo aceptado al editar (números). */
    min?: number;
}

export type SettingTopic =
    | 'media'
    | 'voice'
    | 'monitoring'
    | 'reports'
    | 'other';

export const SETTING_TOPICS: {
    key: SettingTopic;
    title: string;
    description: string;
}[] = [
    {
        key: 'media',
        title: 'Video e imágenes',
        description: 'Cuánto material pide SAM a la cámara cuando algo pasa.',
    },
    {
        key: 'voice',
        title: 'Verificación por llamada',
        description:
            'La llamada que confirma si un botón de pánico es real antes de escalar.',
    },
    {
        key: 'monitoring',
        title: 'Monitoreo de unidades',
        description:
            'Cuándo avisar de una unidad que deja de reportar o se detiene donde no debe.',
    },
    {
        key: 'reports',
        title: 'Reportes y evidencia',
        description: 'Lo que acompaña a los reportes y cuánto se guarda.',
    },
    {
        key: 'other',
        title: 'Otros ajustes',
        description: 'Ajustes sin categoría propia.',
    },
];

export const KNOWN_SETTINGS: Record<string, KnownSetting> = {
    'media.clip_window_seconds': {
        label: 'Duración del video',
        help: 'Segundos de video antes y después del momento del evento.',
        topic: 'media',
        unit: 'seconds',
        min: 1,
    },
    'media.still_window_minutes': {
        label: 'Ventana de imágenes fijas',
        help: 'Minutos alrededor del evento en los que se reparten las capturas.',
        topic: 'media',
        unit: 'minutes',
        min: 1,
    },
    'media.still_count': {
        label: 'Número de imágenes fijas',
        help: 'Capturas que se piden alrededor del momento del evento.',
        topic: 'media',
        unit: 'count',
        min: 0,
    },
    'media.retrieval_max_age_hours': {
        label: 'Antigüedad máxima del video',
        help: 'Pasado este tiempo la cámara ya sobrescribió la grabación y no se pide.',
        topic: 'media',
        unit: 'hours',
        min: 1,
    },
    'context.safety_correlation_minutes': {
        label: 'Ventana de conducción a revisar',
        help: 'Minutos alrededor del evento en los que se buscan frenadas y maniobras bruscas.',
        topic: 'media',
        unit: 'minutes',
        min: 0,
    },
    'voice.verification_enabled': {
        label: 'Llamar para verificar un pánico',
        help: 'SAM llama al contacto y le pide confirmar con el teclado si la emergencia es real.',
        topic: 'voice',
    },
    'voice.call_attempts': {
        label: 'Intentos de llamada',
        help: 'Veces que se reintenta la llamada antes de escalar sin respuesta.',
        topic: 'voice',
        unit: 'count',
        min: 1,
    },
    'voice.retry_delay_seconds': {
        label: 'Espera entre llamadas',
        help: 'Tiempo entre un intento de llamada y el siguiente.',
        topic: 'voice',
        unit: 'seconds',
        min: 1,
    },
    'voice.verification_contacts': {
        label: 'Teléfonos de verificación',
        help: 'A quién se llama para verificar un pánico.',
        topic: 'voice',
    },
    'monitoring.offline_alert_minutes': {
        label: 'Unidad sin conexión en ruta',
        help: 'Minutos sin señal, con la unidad en movimiento, antes de avisar. 0 lo desactiva.',
        topic: 'monitoring',
        unit: 'minutes',
        min: 0,
    },
    'monitoring.offline_parked_alert_minutes': {
        label: 'Unidad sin conexión estacionada',
        help: 'Minutos sin señal con la unidad detenida antes de avisar. 0 lo desactiva.',
        topic: 'monitoring',
        unit: 'minutes',
        min: 0,
    },
    'monitoring.stop_alert_minutes': {
        label: 'Parada sospechosa',
        help: 'Minutos detenida fuera de sus zonas conocidas antes de avisar. 0 lo desactiva.',
        topic: 'monitoring',
        unit: 'minutes',
        min: 0,
    },
    'branding.report_footer': {
        label: 'Pie de página de reportes',
        help: 'Texto al final de los reportes generados.',
        topic: 'reports',
    },
    'compliance.evidence_retention_days': {
        label: 'Tiempo que se guarda la evidencia',
        help: 'Días que se conservan videos e imágenes de los incidentes.',
        topic: 'reports',
        unit: 'days',
        min: 1,
    },
};

const UNIT_SUFFIX: Record<SettingUnit, [string, string]> = {
    seconds: ['segundo', 'segundos'],
    minutes: ['minuto', 'minutos'],
    hours: ['hora', 'horas'],
    days: ['día', 'días'],
    count: ['', ''],
};

export function unitLabel(
    unit: SettingUnit | undefined,
    value: number,
): string {
    if (!unit || unit === 'count') {
        return '';
    }

    return UNIT_SUFFIX[unit][value === 1 ? 0 : 1];
}

export function describeSetting(setting: SettingRow): KnownSetting {
    return (
        KNOWN_SETTINGS[setting.key] ?? {
            label: humanizeCode(setting.key.split('.').pop()),
            help: '',
            topic: 'other',
        }
    );
}

/** Valor de un ajuste para lectura: Sí/No, números con unidad, listas. */
export function formatSettingValue(value: unknown, unit?: SettingUnit): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Sí' : 'No';
    }

    if (typeof value === 'number') {
        const suffix = unitLabel(unit, value);

        return suffix
            ? `${formatNumber(value)} ${suffix}`
            : formatNumber(value);
    }

    if (typeof value === 'string') {
        return value;
    }

    if (Array.isArray(value)) {
        return value.length === 0
            ? '—'
            : value.map((item) => formatSettingValue(item)).join(', ');
    }

    return JSON.stringify(value);
}

/** Segundos → minutos para mostrar (hasta 2 decimales, sin ceros de más). */
export function secondsToMinutesInput(seconds: number): string {
    return String(Math.round((seconds / 60) * 100) / 100);
}
