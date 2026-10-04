import { describe, expect, it } from 'vitest';
import {
    formatCurrency,
    formatDate,
    formatDateTime,
    formatDateWith,
    formatMonthYear,
    formatNumber,
    formatPercent,
    formatShortDate,
    toDate,
} from '@/lib/format';

describe('formatNumber', () => {
    it('usa separador de miles y punto decimal de es-MX', () => {
        expect(formatNumber(1234.5)).toBe('1,234.5');
        expect(formatNumber(0)).toBe('0');
    });

    it('respeta las opciones de Intl', () => {
        expect(formatNumber(1.23456, { maximumFractionDigits: 2 })).toBe(
            '1.23',
        );
    });
});

describe('formatPercent', () => {
    it('interpreta el valor como proporción por omisión', () => {
        expect(formatPercent(0.944)).toBe('94.4 %');
        expect(formatPercent(1)).toBe('100 %');
    });

    it('acepta valores ya en escala 0..100', () => {
        expect(formatPercent(12.5, { alreadyPercent: true })).toBe('12.5 %');
    });

    it('limita los decimales', () => {
        expect(formatPercent(0.12345, { digits: 0 })).toBe('12 %');
    });
});

describe('formatCurrency', () => {
    it('siempre muestra dos decimales y MXN por omisión', () => {
        expect(formatCurrency(1234.5)).toBe('1,234.50 MXN');
        expect(formatCurrency(0)).toBe('0.00 MXN');
    });

    it('normaliza la moneda del backend a mayúsculas', () => {
        expect(formatCurrency(10, 'usd')).toBe('10.00 USD');
    });

    it('cae a MXN cuando el backend manda null', () => {
        expect(formatCurrency(10, null)).toBe('10.00 MXN');
    });
});

describe('toDate', () => {
    it('toma una fecha sin hora como día local, no como medianoche UTC', () => {
        const date = toDate('2026-06-30');

        expect(date.getFullYear()).toBe(2026);
        expect(date.getMonth()).toBe(5);
        expect(date.getDate()).toBe(30);
    });

    it('devuelve la misma instancia si ya es Date', () => {
        const date = new Date();

        expect(toDate(date)).toBe(date);
    });
});

describe('formatDate', () => {
    it('formatea como fecha corta', () => {
        expect(formatDate('2026-06-09')).toBe('9 jun 2026');
    });

    it('no corre la fecha un día hacia atrás en México', () => {
        expect(formatDate('2026-06-30')).toBe('30 jun 2026');
    });

    it.each([null, undefined, '', 'no-es-fecha'])(
        'muestra un guion con %j',
        (value) => {
            expect(formatDate(value)).toBe('—');
        },
    );
});

describe('formatDateTime', () => {
    it('usa reloj de 24 horas', () => {
        expect(formatDateTime(new Date(2026, 5, 9, 14, 5))).toBe(
            '9 jun 2026, 14:05',
        );
    });

    it('convierte un instante UTC a la hora local', () => {
        expect(formatDateTime('2026-06-09T20:05:00Z')).toBe(
            '9 jun 2026, 14:05',
        );
    });

    it.each([null, undefined, 'no-es-fecha'])(
        'muestra un guion con %j',
        (value) => {
            expect(formatDateTime(value)).toBe('—');
        },
    );
});

describe('formatDateWith', () => {
    it('usa reloj de 24 horas por omisión', () => {
        expect(
            formatDateWith(new Date(2026, 9, 2, 0, 5), {
                hour: '2-digit',
                minute: '2-digit',
            }),
        ).toBe('00:05');
    });

    it('muestra un guion sin fecha', () => {
        expect(formatDateWith(null, { day: 'numeric' })).toBe('—');
    });
});

describe('formatShortDate', () => {
    it('omite el año y el punto de la abreviatura', () => {
        expect(formatShortDate('2026-10-02')).toBe('2 oct');
    });
});

describe('formatMonthYear', () => {
    it('capitaliza el mes', () => {
        expect(formatMonthYear('2026-10-02')).toBe('Octubre de 2026');
    });

    it('muestra un guion sin fecha', () => {
        expect(formatMonthYear(null)).toBe('—');
    });
});
