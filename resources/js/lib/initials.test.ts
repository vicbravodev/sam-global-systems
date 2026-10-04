import { describe, expect, it } from 'vitest';
import { getInitials } from '@/lib/initials';

describe('getInitials', () => {
    it('toma la inicial del primer y del último nombre', () => {
        expect(getInitials('Ana María López')).toBe('AL');
    });

    it('ignora espacios de más', () => {
        expect(getInitials('  ana   lópez ')).toBe('AL');
    });

    it('con una sola palabra devuelve sus primeras letras', () => {
        expect(getInitials('serviexpress')).toBe('S');
        expect(getInitials('serviexpress', 2)).toBe('SE');
    });

    it('con un nombre vacío devuelve cadena vacía', () => {
        expect(getInitials('   ')).toBe('');
    });
});
