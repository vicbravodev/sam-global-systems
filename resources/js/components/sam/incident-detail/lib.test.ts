import { describe, expect, it } from 'vitest';
import { contextCaptureLabel, emptyMediaMessage } from './lib';

describe('contextCaptureLabel', () => {
    it('names the camera and how far from the event', () => {
        expect(contextCaptureLabel('driver', 1)).toBe('Cabina · 1 s después');
        expect(contextCaptureLabel('road', -119)).toBe('Camino · 1 min antes');
        expect(contextCaptureLabel('road', 0)).toBe('Camino · al momento');
    });

    it('falls back when camera or offset are unknown', () => {
        expect(contextCaptureLabel(null, null)).toBe('Foto de contexto');
        expect(contextCaptureLabel('analog1', 30)).toBe(
            'Foto de contexto · 30 s después',
        );
    });
});

describe('emptyMediaMessage', () => {
    it('says the search is running while a request is in flight', () => {
        expect(emptyMediaMessage(['failed', 'processing'])).toMatch(
            /^Buscando/,
        );
    });

    it('explains the camera had nothing once every request closed', () => {
        expect(emptyMediaMessage(['expired'])).toMatch(/^La cámara no tenía/);
    });

    it('keeps the plain message when nothing was ever requested', () => {
        expect(emptyMediaMessage([])).toBe(
            'Sin media disponible para este evento.',
        );
    });
});
