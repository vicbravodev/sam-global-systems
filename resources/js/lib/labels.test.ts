import { describe, expect, it } from 'vitest';
import { channelLabel } from '@/lib/labels';

describe('channelLabel', () => {
    it('nombra el canal de la app del chofer en Samsara', () => {
        expect(channelLabel('samsara_driver_app')).toBe('App de Samsara');
    });
});
