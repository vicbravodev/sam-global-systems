import { describe, expect, it } from 'vitest';
import {
    DEFAULT_TENANT_TIMEZONE,
    TENANT_TIMEZONES,
    timezoneLabel,
} from '@/lib/timezones';

describe('timezoneLabel', () => {
    it('traduce una zona conocida', () => {
        expect(timezoneLabel('America/Monterrey')).toBe('Noreste (Monterrey)');
    });

    it('muestra la zona tal cual si no está en el catálogo', () => {
        expect(timezoneLabel('Europe/Madrid')).toBe('Europe/Madrid');
    });

    it('avisa que sin zona rige UTC', () => {
        expect(timezoneLabel(null)).toBe('Sin definir (UTC)');
    });
});

describe('TENANT_TIMEZONES', () => {
    it('incluye la zona por omisión', () => {
        expect(TENANT_TIMEZONES.map((tz) => tz.value)).toContain(
            DEFAULT_TENANT_TIMEZONE,
        );
    });

    it('sólo ofrece zonas IANA que el navegador reconoce', () => {
        for (const { value } of TENANT_TIMEZONES) {
            expect(() =>
                new Intl.DateTimeFormat('es-MX', { timeZone: value }).format(),
            ).not.toThrow();
        }
    });
});
