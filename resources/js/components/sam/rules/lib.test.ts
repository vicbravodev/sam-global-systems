import { describe, expect, it } from 'vitest';
import {
    mappingSource,
    outcomeGroup,
    previewPosition,
    priorityForPlacement,
    scopeForConditions,
} from './lib';
import type { DecisionRuleRow, MappingRuleRow } from './types';

function rule(id: number, priority: number): DecisionRuleRow {
    return { id, priority } as DecisionRuleRow;
}

function mapping(
    externalEventType: string,
    conditions: Record<string, unknown> | null,
): MappingRuleRow {
    return { externalEventType, conditions } as MappingRuleRow;
}

describe('previewPosition', () => {
    const rules = [rule(1, 50), rule(2, 80), rule(3, 50)];

    it('ordena por prioridad descendente', () => {
        expect(previewPosition(rules, { id: null, priority: 90 })).toBe(1);
        expect(previewPosition(rules, { id: null, priority: 10 })).toBe(4);
    });

    it('en empate, una regla nueva va después de las existentes', () => {
        expect(previewPosition(rules, { id: null, priority: 50 })).toBe(4);
    });

    it('en empate, una regla existente respeta su antigüedad', () => {
        const others = rules.filter((r) => r.id !== 1);

        expect(previewPosition(others, { id: 1, priority: 50 })).toBe(2);
    });
});

describe('priorityForPlacement', () => {
    const anchors = [rule(1, 100), rule(2, 90), rule(3, 89)];

    it('sin reglas usa la prioridad por omisión', () => {
        expect(priorityForPlacement([], 'first')).toBe(100);
    });

    it('antes que todas: una más que la primera, con tope 255', () => {
        expect(priorityForPlacement(anchors, 'first')).toBe(101);
        expect(priorityForPlacement([rule(1, 255)], 'first')).toBe(255);
    });

    it('después de una regla con hueco: el punto medio', () => {
        expect(priorityForPlacement(anchors, 'after:1')).toBe(95);
    });

    it('sin hueco: empata con la anterior', () => {
        expect(priorityForPlacement(anchors, 'after:2')).toBe(90);
    });

    it('después de la última: una menos, sin bajar de 0', () => {
        expect(priorityForPlacement(anchors, 'after:3')).toBe(88);
        expect(priorityForPlacement([rule(1, 0)], 'after:1')).toBe(0);
    });

    it('con una referencia desconocida, al final', () => {
        expect(priorityForPlacement(anchors, 'after:999')).toBe(89);
    });
});

describe('outcomeGroup', () => {
    it.each([
        ['INCIDENT', 'incident'],
        ['ESCALATE', 'incident'],
        ['REQUIRE_HUMAN_REVIEW', 'review'],
        ['ALERT', 'alert'],
        ['IGNORE', 'quiet'],
        ['LOG_ONLY', 'quiet'],
        [null, 'ai'],
    ])('%s → %s', (code, group) => {
        expect(outcomeGroup(code)).toBe(group);
    });
});

describe('scopeForConditions', () => {
    it('detecta un filtro por tipo de evento aunque esté anidado', () => {
        expect(
            scopeForConditions({
                all: [{ field: 'event_type_code', operator: 'eq' }],
            }),
        ).toBe('event_type');
    });

    it('sin filtro por tipo, aplica a todo el tenant', () => {
        expect(scopeForConditions({ field: 'speed', operator: 'gt' })).toBe(
            'tenant',
        );
    });
});

describe('mappingSource', () => {
    it('muestra el nombre real de una alerta configurada en Samsara', () => {
        expect(
            mappingSource(
                mapping('AlertIncident', { alertDescription: 'Panic Button' }),
            ),
        ).toEqual({
            title: 'Panic Button',
            detail: 'Alerta configurada (Alert Incident)',
        });
    });

    it('separa el tipo en palabras y describe las condiciones', () => {
        expect(
            mappingSource(mapping('HeavySpeeding', { severity: 'high' })),
        ).toEqual({
            title: 'Heavy Speeding',
            detail: 'Solo si severity = high',
        });
    });

    it('sin condiciones no agrega detalle', () => {
        expect(mappingSource(mapping('harsh_brake', null))).toEqual({
            title: 'harsh brake',
            detail: null,
        });
    });
});
