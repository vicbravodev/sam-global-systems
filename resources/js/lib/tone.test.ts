import { describe, expect, it } from 'vitest';
import { TONE_DOT, toneDotFor } from '@/lib/tone';
import type { ToneLabel } from '@/lib/tone';

const STATUS: Record<string, ToneLabel> = {
    active: { label: 'Activo', tone: 'ok' },
    down: { label: 'Caído', tone: 'critical' },
};

describe('toneDotFor', () => {
    it('devuelve el punto del tono del código', () => {
        expect(toneDotFor(STATUS, 'down')).toBe(TONE_DOT.critical);
    });

    it('no pinta un punto para un código desconocido', () => {
        expect(toneDotFor(STATUS, 'mystery')).toBeUndefined();
    });

    it('no confunde propiedades heredadas con códigos', () => {
        expect(toneDotFor(STATUS, 'toString')).toBeUndefined();
    });
});
