import type {
    FlatConditions,
    ScalarValue,
    VisualGroup,
    VisualLeaf,
    VisualNode,
} from './types';

/**
 * Lógica pura de parse/serialize entre el JSON persistido y el modelo
 * visual. Si un JSON es válido pero no representable visualmente, parse
 * devuelve null y la UI muestra el aviso de "estructura avanzada" — nunca
 * se pierde dato.
 */

export const OPERATOR_LABELS: Record<string, string> = {
    eq: 'es igual a',
    neq: 'es distinto de',
    gt: 'es mayor que',
    gte: 'es mayor o igual que',
    lt: 'es menor que',
    lte: 'es menor o igual que',
    in: 'es alguno de',
    not_in: 'no es ninguno de',
    contains: 'contiene el texto',
    is_null: 'no tiene valor',
    is_not_null: 'tiene valor',
};

export const KNOWN_OPERATORS = Object.keys(OPERATOR_LABELS);

export const NO_VALUE_OPERATORS = ['is_null', 'is_not_null'];

export const LIST_OPERATORS = ['in', 'not_in'];

let idCounter = 0;

function nextId(): string {
    idCounter += 1;

    return `cb-${idCounter}`;
}

export function makeLeaf(field = '', operator = 'eq'): VisualLeaf {
    return { kind: 'leaf', id: nextId(), field, operator, value: '' };
}

export function makeGroup(logic: 'all' | 'any' = 'all'): VisualGroup {
    return { kind: 'group', id: nextId(), logic, children: [] };
}

function isScalar(value: unknown): value is ScalarValue {
    return (
        value === null ||
        typeof value === 'string' ||
        typeof value === 'number' ||
        typeof value === 'boolean'
    );
}

function parseLeaf(node: Record<string, unknown>): VisualLeaf | null {
    const field = node.field;
    const operator = node.operator;

    if (typeof field !== 'string' || typeof operator !== 'string') {
        return null;
    }

    if (!KNOWN_OPERATORS.includes(operator)) {
        return null;
    }

    const value = node.value;

    if (LIST_OPERATORS.includes(operator)) {
        if (value !== undefined && !Array.isArray(value)) {
            return null;
        }

        if (Array.isArray(value) && value.some((item) => !isScalar(item))) {
            return null;
        }

        return {
            kind: 'leaf',
            id: nextId(),
            field,
            operator,
            value: value ?? [],
        };
    }

    if (value !== undefined && !isScalar(value)) {
        return null;
    }

    return { kind: 'leaf', id: nextId(), field, operator, value: value ?? '' };
}

function parseNode(node: unknown): VisualNode | null {
    if (node === null || typeof node !== 'object' || Array.isArray(node)) {
        return null;
    }

    const record = node as Record<string, unknown>;
    const hasAll = 'all' in record;
    const hasAny = 'any' in record;

    if (hasAll || hasAny) {
        if (hasAll && hasAny) {
            return null;
        }

        const children = hasAll ? record.all : record.any;

        if (!Array.isArray(children)) {
            return null;
        }

        const parsed: VisualNode[] = [];

        for (const child of children) {
            const node = parseNode(child);

            if (node === null) {
                return null;
            }

            parsed.push(node);
        }

        return {
            kind: 'group',
            id: nextId(),
            logic: hasAll ? 'all' : 'any',
            children: parsed,
        };
    }

    return parseLeaf(record);
}

/**
 * JSON persistido → árbol visual. Devuelve null si el JSON es válido pero
 * no representable (operador desconocido, valores anidados, etc.).
 */
export function parseTree(json: Record<string, unknown>): VisualGroup | null {
    if (Object.keys(json).length === 0) {
        return makeGroup('all');
    }

    const node = parseNode(json);

    if (node === null) {
        return null;
    }

    if (node.kind === 'leaf') {
        return { kind: 'group', id: nextId(), logic: 'all', children: [node] };
    }

    return node;
}

/** Árbol visual → JSON persistido. Grupo raíz vacío → `{}`. */
export function serializeTree(group: VisualGroup): Record<string, unknown> {
    if (group.children.length === 0) {
        return {};
    }

    return serializeGroup(group);
}

function serializeGroup(group: VisualGroup): Record<string, unknown> {
    return {
        [group.logic]: group.children.map((child) =>
            child.kind === 'group'
                ? serializeGroup(child)
                : serializeLeaf(child),
        ),
    };
}

function serializeLeaf(leaf: VisualLeaf): Record<string, unknown> {
    if (NO_VALUE_OPERATORS.includes(leaf.operator)) {
        return { field: leaf.field, operator: leaf.operator };
    }

    return { field: leaf.field, operator: leaf.operator, value: leaf.value };
}

/** Dict plano → filas editables. Null si algún valor no es escalar. */
export function parseFlat(
    json: Record<string, unknown>,
): { id: string; key: string; value: ScalarValue }[] | null {
    if (Array.isArray(json)) {
        return null;
    }

    const rows: { id: string; key: string; value: ScalarValue }[] = [];

    for (const [key, value] of Object.entries(json)) {
        if (!isScalar(value)) {
            return null;
        }

        rows.push({ id: nextId(), key, value });
    }

    return rows;
}

export function serializeFlat(
    rows: { key: string; value: ScalarValue }[],
): FlatConditions {
    const out: FlatConditions = {};

    for (const row of rows) {
        if (row.key.trim() !== '') {
            out[row.key.trim()] = row.value;
        }
    }

    return out;
}

export function newFlatRow(): { id: string; key: string; value: ScalarValue } {
    return { id: nextId(), key: '', value: '' };
}
