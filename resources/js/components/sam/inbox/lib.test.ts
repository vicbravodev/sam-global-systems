import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { MockIncident } from '@/types/sam';
import { inboxRows, isOwnEcho, openOnly } from './lib';

let nextId = 0;

function incident(overrides: Partial<MockIncident> = {}): MockIncident {
    nextId += 1;

    return {
        id: `INC-${nextId}`,
        incidentId: nextId,
        number: nextId,
        title: 'Botón de pánico',
        severity: 'critical',
        status: 'new',
        statusLabel: 'Abierto',
        provider: 'samsara',
        asset: 'T-100',
        driver: 'Ana López',
        assignee: null,
        claimedBy: null,
        claimedAt: null,
        slaSeconds: 600,
        slaTotal: 900,
        ageMin: 5,
        eventType: 'panic_button',
        location: 'Monterrey',
        aiPlaceholder: false,
        aiConfidence: null,
        aiDecision: 'incident',
        aiReason: '',
        ...overrides,
    };
}

const ME = { id: 1, name: 'Yo', initials: 'YO' };
const OTHER = { id: 2, name: 'Otra', initials: 'OT' };

describe('openOnly', () => {
    it('saca resueltos, cerrados y descartados', () => {
        const open = incident({ status: 'new' });
        const rows = [
            open,
            incident({ status: 'resolved' }),
            incident({ status: 'closed' }),
            incident({ status: 'discarded' }),
        ];

        expect(openOnly(rows)).toEqual([open]);
    });
});

describe('inboxRows', () => {
    const urgent = incident({ slaSeconds: 60, assignee: ME });
    const calm = incident({ slaSeconds: 3000 });
    const soon = incident({ slaSeconds: 600, assignee: OTHER });
    const discarded = incident({ status: 'discarded', slaSeconds: 10 });
    const resolvedMine = incident({
        status: 'resolved',
        slaSeconds: 0,
        assignee: ME,
    });
    const all = [calm, soon, urgent, discarded, resolvedMine];
    const open = openOnly(all);

    function ids(rows: MockIncident[]): string[] {
        return rows.map((row) => row.id);
    }

    it('abiertos: lo más urgente primero', () => {
        expect(ids(inboxRows('open', all, open, ME.id))).toEqual(
            ids([urgent, soon, calm]),
        );
    });

    it('míos: incluye los ya resueltos que me asignaron', () => {
        expect(ids(inboxRows('mine', all, open, ME.id))).toEqual(
            ids([resolvedMine, urgent]),
        );
    });

    it('míos sin usuario no muestra nada', () => {
        expect(inboxRows('mine', all, open, null)).toEqual([]);
    });

    it('sin asignar: sólo abiertos sin responsable', () => {
        expect(ids(inboxRows('unassigned', all, open, ME.id))).toEqual(
            ids([calm]),
        );
    });

    it('SLA crítico: abiertos con menos de 15 minutos', () => {
        expect(ids(inboxRows('sla', all, open, ME.id))).toEqual(
            ids([urgent, soon]),
        );
    });

    it('un SLA vencido cuenta como crítico', () => {
        const overdue = incident({ slaSeconds: -120 });

        expect(ids(inboxRows('sla', [overdue], [overdue], null))).toEqual(
            ids([overdue]),
        );
    });

    it('descartados: sólo los descartados', () => {
        expect(ids(inboxRows('discarded', all, open, ME.id))).toEqual(
            ids([discarded]),
        );
    });

    it('todos: todo, ordenado por SLA', () => {
        expect(ids(inboxRows('all', all, open, ME.id))).toEqual(
            ids([resolvedMine, discarded, urgent, soon, calm]),
        );
    });

    it('no reordena el arreglo que recibe', () => {
        const before = ids(all);

        inboxRows('all', all, open, ME.id);

        expect(ids(all)).toEqual(before);
    });
});

describe('isOwnEcho', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-03T18:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('reconoce el eco de una acción propia dentro de la ventana', () => {
        const acted = new Map([[7, Date.now() + 3000]]);

        expect(isOwnEcho(acted, 7)).toBe(true);
        expect(isOwnEcho(acted, 8)).toBe(false);
    });

    it('olvida la acción al vencer la ventana', () => {
        const acted = new Map([[7, Date.now() - 1]]);

        expect(isOwnEcho(acted, 7)).toBe(false);
        expect(acted.has(7)).toBe(false);
    });
});
