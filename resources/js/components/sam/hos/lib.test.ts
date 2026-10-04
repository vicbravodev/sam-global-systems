import { afterEach, describe, expect, it, vi } from 'vitest';
import type { HosFleetRow, HosNudge } from '@/types/hos';
import {
    clockBars,
    deliveryLines,
    driverTabFromUrl,
    fleetOutageLabel,
    hosReloadProps,
    isWorkingStatus,
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

    it('quien está parado no lee "Agotado" en rojo: el reloj en 0 es lo normal', () => {
        const clocks = { break: 900, drive: 0, shift: 0, cycle: 30000 };

        expect(
            clockBars(clocks, 'offDuty').map((bar) => [
                bar.key,
                bar.tone,
                bar.valueLabel,
            ]),
        ).toEqual([
            ['break', 'neutral', '15 min'],
            ['drive', 'neutral', 'En 0'],
            ['shift', 'neutral', 'En 0'],
            ['cycle', 'ok', '8 h 20 min'],
        ]);
        expect(clockBars(clocks, 'driving')[1]?.tone).toBe('critical');
        expect(clockBars(clocks, 'onDuty')[1]?.valueLabel).toBe('Agotado');
        // Sin estado conocido no se presume que descansa.
        expect(clockBars(clocks, null)[1]?.tone).toBe('critical');
    });
});

describe('isWorkingStatus', () => {
    it('manejar, en turno o en patio cuentan como trabajo', () => {
        expect(isWorkingStatus('driving')).toBe(true);
        expect(isWorkingStatus('onDuty')).toBe(true);
        expect(isWorkingStatus('yardMove')).toBe(true);
        expect(isWorkingStatus('offDuty')).toBe(false);
        expect(isWorkingStatus('sleeperBed')).toBe(false);
        expect(isWorkingStatus('personalConveyance')).toBe(false);
    });
});

describe('fleetOutageLabel', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('sin filas y con una lectura vieja dice desde cuándo no lee Samsara', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-04T18:00:00Z'));

        expect(
            fleetOutageLabel({
                rows: [],
                lastObservedAt: '2026-10-04T16:00:00Z',
            }),
        ).toBe('Sin lectura de Samsara desde hace 2 horas');
        expect(fleetOutageLabel({ rows: [], lastObservedAt: null })).toBeNull();
        // Una lectura de hace un minuto no es una caída.
        expect(
            fleetOutageLabel({
                rows: [],
                lastObservedAt: '2026-10-04T17:59:00Z',
            }),
        ).toBeNull();
        expect(
            fleetOutageLabel({
                rows: [{} as HosFleetRow],
                lastObservedAt: '2026-10-04T16:00:00Z',
            }),
        ).toBeNull();
    });
});

describe('textos del panel', () => {
    it('lo más cercano de una fila', () => {
        const row = { minRemainingSeconds: 900 } as Pick<
            HosFleetRow,
            'minRemainingSeconds' | 'dutyStatus'
        >;

        expect(nearestLabel(row)).toBe('15 min');
        expect(
            nearestLabel({ minRemainingSeconds: 0, dutyStatus: 'driving' }),
        ).toBe('Agotado');
        expect(
            nearestLabel({ minRemainingSeconds: 0, dutyStatus: 'sleeperBed' }),
        ).toBe('En 0');
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

describe('hosReloadProps', () => {
    it('el sondeo recarga hos sólo con la pestaña HOS abierta', () => {
        expect(hosReloadProps(true, 'hos')).toEqual(['hos']);
        expect(hosReloadProps(true, 'resumen')).toBeNull();
        expect(hosReloadProps(false, 'hos')).toBeNull();
    });
});
