import { describe, expect, it } from 'vitest';
import type { HosFleetRow, HosNudge } from '@/types/hos';
import {
    clockBars,
    deliveryLines,
    driverTabFromUrl,
    nearestLabel,
    nudgeTitle,
    nudgesLabel,
} from './lib';

describe('clockBars', () => {
    it('pinta cada reloj con su tono y su tiempo', () => {
        const bars = clockBars({
            break: 900,
            drive: 0,
            shift: 30000,
            cycle: null,
        });

        expect(bars.map((bar) => [bar.key, bar.tone, bar.valueLabel])).toEqual([
            ['break', 'warn', '15 min'],
            ['drive', 'critical', 'Agotado'],
            ['shift', 'ok', '8 h 20 min'],
            ['cycle', 'neutral', 'Sin dato'],
        ]);
        expect(bars[0]?.max).toBe(28800);
    });
});

describe('textos del panel', () => {
    it('lo más cercano de una fila', () => {
        const row = { minRemainingSeconds: 900 } as Pick<
            HosFleetRow,
            'minRemainingSeconds'
        >;

        expect(nearestLabel(row)).toBe('15 min');
        expect(nearestLabel({ minRemainingSeconds: 0 })).toBe('Agotado');
        expect(nearestLabel({ minRemainingSeconds: null })).toBe('—');
    });

    it('avisos enviados (los que de verdad salieron, no el escalón)', () => {
        expect(nudgesLabel(0)).toBe('Sin avisos enviados');
        expect(nudgesLabel(1)).toBe('1 aviso enviado');
        expect(nudgesLabel(3)).toBe('3 avisos enviados');
        expect(nudgesLabel(1200)).toBe('1,200 avisos enviados');
    });

    it('cada entrega con su canal y su resultado', () => {
        const nudge: HosNudge = {
            id: 1,
            step: 1,
            notice: 'break_insist',
            createdAt: '2026-10-04T18:00:00Z',
            deliveries: [
                { channel: 'samsara_driver_app', status: 'delivered' },
                { channel: 'voice', status: 'failed' },
            ],
        };

        expect(nudgeTitle(nudge)).toBe('Insistencia: descanso');
        expect(
            deliveryLines(nudge).map((line) => [line.text, line.tone]),
        ).toEqual([
            ['App de Samsara: entregado', 'ok'],
            ['Llamada de voz: falló', 'critical'],
        ]);
        expect(nudgeTitle({ ...nudge, notice: null })).toBe('Aviso');
    });

    it('la pestaña sale de ?pestana=', () => {
        expect(driverTabFromUrl('/acme/drivers/7?pestana=hos')).toBe('hos');
        expect(driverTabFromUrl('/acme/drivers/7')).toBe('resumen');
        expect(driverTabFromUrl('/acme/drivers/7?pestana=otra')).toBe(
            'resumen',
        );
    });
});
