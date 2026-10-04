import { describe, expect, it } from 'vitest';
import { channelLabel, eventTypeLabel } from '@/lib/labels';

describe('channelLabel', () => {
    it('nombra el canal de la app del chofer en Samsara', () => {
        expect(channelLabel('samsara_driver_app')).toBe('App de Samsara');
    });
});

describe('eventTypeLabel', () => {
    it('nombra los eventos e incidentes de horas de servicio', () => {
        expect(eventTypeLabel('hos_limit_exceeded')).toBe(
            'Horas de servicio rebasadas',
        );
        expect(eventTypeLabel('hos_unattended')).toBe(
            'Horas de servicio sin atender',
        );
        expect(eventTypeLabel('hos_violation')).toBe(
            'Violación de horas de servicio',
        );
        expect(eventTypeLabel('hos_compliance')).toBe(
            'Horas de servicio (HOS)',
        );
    });
});
