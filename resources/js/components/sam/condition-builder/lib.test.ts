import { describe, expect, it } from 'vitest';
import {
    makeGroup,
    makeLeaf,
    parseFlat,
    parseTree,
    serializeFlat,
    serializeTree,
} from './lib';

describe('parseTree / serializeTree', () => {
    it.each([
        ['vacío', {}],
        [
            'un grupo plano',
            {
                all: [
                    {
                        field: 'event_type_code',
                        operator: 'eq',
                        value: 'panic',
                    },
                    { field: 'speed_kph', operator: 'gt', value: 80 },
                ],
            },
        ],
        [
            'grupos anidados',
            {
                any: [
                    { field: 'severity', operator: 'in', value: ['high'] },
                    {
                        all: [
                            { field: 'driver_id', operator: 'is_null' },
                            {
                                field: 'location',
                                operator: 'contains',
                                value: 'Monterrey',
                            },
                        ],
                    },
                ],
            },
        ],
    ])('ida y vuelta sin pérdida: %s', (_, json) => {
        const tree = parseTree(json);

        expect(tree).not.toBeNull();
        expect(serializeTree(tree!)).toEqual(json);
    });

    it('envuelve una condición suelta en un grupo "todas"', () => {
        const tree = parseTree({ field: 'a', operator: 'eq', value: 1 });

        expect(serializeTree(tree!)).toEqual({
            all: [{ field: 'a', operator: 'eq', value: 1 }],
        });
    });

    it.each([
        ['operador desconocido', { field: 'a', operator: 'regex', value: 'x' }],
        ['valor anidado', { field: 'a', operator: 'eq', value: { b: 1 } }],
        ['lista con objetos', { field: 'a', operator: 'in', value: [{}] }],
        ['lista sin arreglo', { field: 'a', operator: 'in', value: 'x' }],
        ['all y any juntos', { all: [], any: [] }],
        ['hijos que no son arreglo', { all: 'x' }],
        ['sin campo', { operator: 'eq', value: 1 }],
        ['hijo inválido', { all: [{ field: 'a', operator: 'nope' }] }],
    ])(
        'no representable (%s): devuelve null para no perder datos',
        (_, json) => {
            expect(parseTree(json)).toBeNull();
        },
    );

    it('no guarda valor en operadores sin valor', () => {
        const group = makeGroup('all');
        const leaf = makeLeaf('driver_id', 'is_not_null');
        leaf.value = 'basura';
        group.children.push(leaf);

        expect(serializeTree(group)).toEqual({
            all: [{ field: 'driver_id', operator: 'is_not_null' }],
        });
    });

    it('da ids únicos a cada nodo', () => {
        const tree = parseTree({
            all: [
                { field: 'a', operator: 'eq', value: 1 },
                { field: 'b', operator: 'eq', value: 2 },
            ],
        })!;
        const ids = [tree.id, ...tree.children.map((child) => child.id)];

        expect(new Set(ids).size).toBe(ids.length);
    });
});

describe('parseFlat / serializeFlat', () => {
    it('ida y vuelta de un diccionario plano', () => {
        const json = { event_type: 'panic', priority: 3, active: true };
        const rows = parseFlat(json);

        expect(serializeFlat(rows!)).toEqual(json);
    });

    it('rechaza valores no escalares', () => {
        expect(parseFlat({ a: [1] })).toBeNull();
    });

    it('descarta filas sin clave y recorta espacios', () => {
        expect(
            serializeFlat([
                { key: '  code ', value: 'x' },
                { key: '   ', value: 'perdido' },
            ]),
        ).toEqual({ code: 'x' });
    });
});
