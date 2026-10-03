/**
 * Helpers de tiempo relativo compartidos por las páginas de flota,
 * conductores, eventos, notificaciones y el Copiloto. Única implementación
 * del "hace N…": no la reescribas en componentes.
 */

import { APP_LOCALE, formatDateWith, formatShortDate } from '@/lib/format';

export function minutesSince(iso: string): number {
    return Math.max(0, Math.floor((Date.now() - Date.parse(iso)) / 60000));
}

/** Umbral bajo el cual una señal se considera "en vivo" (ver watchdog C1). */
const FRESH_MINUTES = 15;

export function isFresh(iso: string | null): boolean {
    return iso !== null && minutesSince(iso) < FRESH_MINUTES;
}

const RELATIVE_LONG = new Intl.RelativeTimeFormat(APP_LOCALE, {
    numeric: 'auto',
});

/**
 * Antigüedad en minutos como texto. `short` (columnas densas): "hace 3 min",
 * "hace 2 h", "hace 5 d". `long` (prosa, Copiloto): "hace 3 minutos",
 * "hace 2 horas", "ayer". Ambos dicen "ahora" bajo el minuto.
 */
export function ageLabel(
    minutes: number,
    style: 'short' | 'long' = 'short',
): string {
    if (Number.isNaN(minutes)) {
        return '—';
    }

    if (minutes < 1) {
        return 'ahora';
    }

    const [value, unit]: [number, 'minute' | 'hour' | 'day'] =
        minutes < 60
            ? [minutes, 'minute']
            : minutes < 1440
              ? [Math.floor(minutes / 60), 'hour']
              : [Math.floor(minutes / 1440), 'day'];

    if (style === 'long') {
        return RELATIVE_LONG.format(-value, unit);
    }

    return `hace ${value} ${unit === 'minute' ? 'min' : unit === 'hour' ? 'h' : 'd'}`;
}

/** Tiempo relativo de un instante ISO (ver `ageLabel`); "—" sin fecha. */
export function relativeLabel(
    iso: string | null,
    style: 'short' | 'long' = 'short',
): string {
    if (iso === null) {
        return '—';
    }

    return ageLabel(minutesSince(iso), style);
}

/** Duración compacta en segundos: "12 s", "3 min", "2 h". */
export function durationLabel(seconds: number): string {
    if (seconds < 60) {
        return `${seconds} s`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)} min`;
    }

    return `${Math.floor(seconds / 3600)} h`;
}

/** Hora corta "14:05" (o "14:05:09" con `seconds`) para columnas densas. */
export function formatClock(
    iso: string | null | undefined,
    { seconds = false } = {},
): string {
    return formatDateWith(iso, {
        hour: '2-digit',
        minute: '2-digit',
        ...(seconds ? { second: '2-digit' } : {}),
    });
}

/** "hoy", "ayer" o fecha corta, para agrupar filas por día. */
export function dayLabel(iso: string): string {
    const date = new Date(iso);
    const today = new Date();
    const startOf = (d: Date) =>
        new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
    const diffDays = Math.round(
        (startOf(today) - startOf(date)) / (24 * 60 * 60 * 1000),
    );

    if (diffDays === 0) {
        return 'Hoy';
    }

    if (diffDays === 1) {
        return 'Ayer';
    }

    return formatShortDate(date);
}
