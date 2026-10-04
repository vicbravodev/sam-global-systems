import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    ageLabel,
    dayLabel,
    durationLabel,
    formatClock,
    hoursMinutesLabel,
    isFresh,
    minutesSince,
    relativeLabel,
} from '@/lib/time';

const NOW = new Date('2026-10-03T18:00:00Z');

function minutesAgo(minutes: number): string {
    return new Date(NOW.getTime() - minutes * 60_000).toISOString();
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(NOW);
});

afterEach(() => {
    vi.useRealTimers();
});

describe('minutesSince', () => {
    it('cuenta minutos completos', () => {
        expect(minutesSince(minutesAgo(3.9))).toBe(3);
    });

    it('nunca es negativo con relojes desfasados', () => {
        expect(minutesSince(minutesAgo(-5))).toBe(0);
    });
});

describe('isFresh', () => {
    it('considera en vivo una señal de menos de 15 minutos', () => {
        expect(isFresh(minutesAgo(14))).toBe(true);
    });

    it('deja de estar en vivo a los 15 minutos', () => {
        expect(isFresh(minutesAgo(15))).toBe(false);
    });

    it('sin señal no está en vivo', () => {
        expect(isFresh(null)).toBe(false);
    });
});

describe('ageLabel', () => {
    it.each([
        [0, 'ahora'],
        [0.5, 'ahora'],
        [3, 'hace 3 min'],
        [59, 'hace 59 min'],
        [60, 'hace 1 h'],
        [1439, 'hace 23 h'],
        [1440, 'hace 1 d'],
        [4320, 'hace 3 d'],
    ])('estilo corto: %s min → %s', (minutes, label) => {
        expect(ageLabel(minutes)).toBe(label);
    });

    it.each([
        [0, 'ahora'],
        [3, 'hace 3 minutos'],
        [120, 'hace 2 horas'],
        [1440, 'ayer'],
    ])('estilo largo: %s min → %s', (minutes, label) => {
        expect(ageLabel(minutes, 'long')).toBe(label);
    });

    it('muestra un guion con NaN', () => {
        expect(ageLabel(Number.NaN)).toBe('—');
    });
});

describe('relativeLabel', () => {
    it('describe un instante ISO', () => {
        expect(relativeLabel(minutesAgo(90))).toBe('hace 1 h');
    });

    it('muestra un guion sin fecha', () => {
        expect(relativeLabel(null)).toBe('—');
    });

    it('muestra un guion con una fecha inválida', () => {
        expect(relativeLabel('no-es-fecha')).toBe('—');
    });
});

describe('durationLabel', () => {
    it.each([
        [12, '12 s'],
        [60, '1 min'],
        [3599, '59 min'],
        [3600, '1 h'],
        [7300, '2 h'],
    ])('%s s → %s', (seconds, label) => {
        expect(durationLabel(seconds)).toBe(label);
    });
});

describe('formatClock', () => {
    it('muestra la hora local en 24 h', () => {
        expect(formatClock('2026-10-03T18:05:09Z')).toBe('12:05');
    });

    it('incluye segundos a pedido', () => {
        expect(formatClock('2026-10-03T18:05:09Z', { seconds: true })).toBe(
            '12:05:09',
        );
    });

    it('muestra un guion sin fecha', () => {
        expect(formatClock(null)).toBe('—');
    });
});

describe('dayLabel', () => {
    it('agrupa lo de hoy y ayer por nombre', () => {
        expect(dayLabel(minutesAgo(30))).toBe('Hoy');
        expect(dayLabel(minutesAgo(24 * 60))).toBe('Ayer');
    });

    it('usa fecha corta para lo anterior', () => {
        expect(dayLabel('2026-09-28T18:00:00Z')).toBe('28 sep');
    });

    it('usa el día local, no el de UTC', () => {
        // 03:00 UTC del 3 de octubre = 21:00 del 2 de octubre en CDMX.
        expect(dayLabel('2026-10-03T03:00:00Z')).toBe('Ayer');
    });
});

describe('hoursMinutesLabel', () => {
    it.each([
        [0, '0 min'],
        [59, '0 min'],
        [2700, '45 min'],
        [3600, '1 h'],
        [3900, '1 h 05 min'],
        [252000, '70 h'],
        [-30, '0 min'],
    ])('%i s → %s', (seconds, expected) => {
        expect(hoursMinutesLabel(seconds)).toBe(expected);
    });
});
