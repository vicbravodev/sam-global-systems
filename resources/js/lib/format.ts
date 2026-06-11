/**
 * Formatos regionales centralizados (es). Usar SIEMPRE estos helpers en vez
 * de toLocaleString/Intl dispersos para que toda la superficie formatee
 * fechas, números y moneda igual.
 */

export const APP_LOCALE = 'es';

/** Moneda por defecto del producto (billing local por transferencia). */
export const DEFAULT_CURRENCY = 'MXN';

/** 1 234,5 — número con separador de miles y decimales opcionales. */
export function formatNumber(
    value: number,
    options?: Intl.NumberFormatOptions,
): string {
    return value.toLocaleString(APP_LOCALE, options);
}

/** Importe monetario: "1,234.50 MXN". La moneda viene del backend si existe. */
export function formatCurrency(
    value: number,
    currency: string | null = DEFAULT_CURRENCY,
): string {
    return `${value.toLocaleString(APP_LOCALE, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })} ${currency ?? DEFAULT_CURRENCY}`.trim();
}

/** Fecha corta: "9 jun 2026". */
export function formatDate(iso: string | Date | null | undefined): string {
    if (!iso) {
        return '—';
    }

    const date = typeof iso === 'string' ? new Date(iso) : iso;

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return date.toLocaleDateString(APP_LOCALE, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/** Fecha y hora: "9 jun 2026, 14:05". */
export function formatDateTime(iso: string | Date | null | undefined): string {
    if (!iso) {
        return '—';
    }

    const date = typeof iso === 'string' ? new Date(iso) : iso;

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return date.toLocaleString(APP_LOCALE, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
