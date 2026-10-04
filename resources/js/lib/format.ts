/**
 * Formatos regionales centralizados (es-MX). Usar SIEMPRE estos helpers en
 * vez de toLocaleString/Intl dispersos para que toda la superficie formatee
 * fechas, números y moneda igual: 1,234.5 · 12.5 % · 9 jun 2026.
 */

export const APP_LOCALE = 'es-MX';

/** Moneda por defecto del producto (billing local por transferencia). */
const DEFAULT_CURRENCY = 'MXN';

/** 1,234.5 — número con separador de miles y decimales opcionales. */
export function formatNumber(
    value: number,
    options?: Intl.NumberFormatOptions,
): string {
    return value.toLocaleString(APP_LOCALE, options);
}

/**
 * Porcentaje. `value` es una proporción (0..1) salvo que `alreadyPercent`
 * indique que ya viene en escala 0..100: formatPercent(0.944) → "94.4 %".
 */
export function formatPercent(
    value: number,
    { alreadyPercent = false, digits = 1 } = {},
): string {
    const pct = alreadyPercent ? value : value * 100;

    return `${pct.toLocaleString(APP_LOCALE, { maximumFractionDigits: digits })} %`;
}

/** Importe monetario: "1,234.50 MXN". La moneda viene del backend si existe. */
export function formatCurrency(
    value: number,
    currency: string | null = DEFAULT_CURRENCY,
): string {
    return `${value.toLocaleString(APP_LOCALE, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })} ${(currency ?? DEFAULT_CURRENCY).toUpperCase()}`.trim();
}

const DATE_ONLY = /^(\d{4})-(\d{2})-(\d{2})$/;

/**
 * Convierte a Date. Las fechas sin hora ("2026-06-30") se interpretan como
 * día de calendario local: `new Date('2026-06-30')` las tomaría como
 * medianoche UTC y en México se mostrarían un día antes.
 */
export function toDate(value: string | Date): Date {
    if (value instanceof Date) {
        return value;
    }

    const dateOnly = DATE_ONLY.exec(value);

    if (dateOnly) {
        return new Date(
            Number(dateOnly[1]),
            Number(dateOnly[2]) - 1,
            Number(dateOnly[3]),
        );
    }

    return new Date(value);
}

/** Fecha corta: "9 jun 2026". */
export function formatDate(iso: string | Date | null | undefined): string {
    if (!iso) {
        return '—';
    }

    const date = toDate(iso);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return date.toLocaleDateString(APP_LOCALE, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/** Fecha y hora con reloj de 24 h: "9 jun 2026, 14:05". */
export function formatDateTime(iso: string | Date | null | undefined): string {
    return formatDateWith(iso, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Fecha con un formato a medida (APP_LOCALE, reloj de 24 h). Para los
 * formatos comunes usa `formatDate`/`formatDateTime`/`formatShortDate`.
 */
export function formatDateWith(
    value: string | Date | null | undefined,
    options: Intl.DateTimeFormatOptions,
): string {
    if (!value) {
        return '—';
    }

    const date = toDate(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return date.toLocaleString(APP_LOCALE, { hourCycle: 'h23', ...options });
}

/** Día corto sin año: "2 oct" (ejes de gráficas, agrupación por día). */
export function formatShortDate(
    value: string | Date | null | undefined,
): string {
    return formatDateWith(value, { day: 'numeric', month: 'short' }).replace(
        '.',
        '',
    );
}

/** Mes y año con mayúscula inicial: "Octubre de 2026" (periodos de cobro). */
export function formatMonthYear(
    value: string | Date | null | undefined,
): string {
    const label = formatDateWith(value, { month: 'long', year: 'numeric' });

    return label.charAt(0).toUpperCase() + label.slice(1);
}
