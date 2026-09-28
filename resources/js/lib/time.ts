/**
 * Helpers de tiempo relativo compartidos por las páginas de flota,
 * conductores, eventos y notificaciones.
 */

export function minutesSince(iso: string): number {
    return Math.max(0, Math.floor((Date.now() - Date.parse(iso)) / 60000));
}

/** Umbral bajo el cual una señal se considera "en vivo" (ver watchdog C1). */
export const FRESH_MINUTES = 15;

export function isFresh(iso: string | null): boolean {
    return iso !== null && minutesSince(iso) < FRESH_MINUTES;
}

/** "hace 3 min" / "hace 2 h" / "hace 5 d"; "ahora" bajo el minuto. */
export function relativeLabel(iso: string | null): string {
    if (iso === null) {
        return '—';
    }

    const minutes = minutesSince(iso);

    if (minutes < 1) {
        return 'ahora';
    }

    if (minutes < 60) {
        return `hace ${minutes} min`;
    }

    if (minutes < 1440) {
        return `hace ${Math.floor(minutes / 60)} h`;
    }

    return `hace ${Math.floor(minutes / 1440)} d`;
}

/** Hora corta "14:05" para columnas densas. */
export function formatClock(iso: string | null): string {
    if (iso === null) {
        return '—';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return date.toLocaleTimeString('es', {
        hour: '2-digit',
        minute: '2-digit',
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

    return date.toLocaleDateString('es', {
        day: 'numeric',
        month: 'short',
    });
}
