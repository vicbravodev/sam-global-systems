/**
 * Tipos del ConditionBuilder. El catálogo de campos llega del backend
 * (App\Support\Conditions\ConditionField::toArray()).
 */

export interface ConditionFieldOption {
    value: string;
    label: string;
}

export interface ConditionFieldDef {
    key: string;
    label: string;
    type: 'string' | 'number' | 'boolean' | 'enum';
    options: ConditionFieldOption[];
    operators: string[];
}

/** Nodo visual interno con id estable para React. */
export interface VisualLeaf {
    kind: 'leaf';
    id: string;
    field: string;
    operator: string;
    value: unknown;
}

export interface VisualGroup {
    kind: 'group';
    id: string;
    logic: 'all' | 'any';
    children: VisualNode[];
}

export type VisualNode = VisualGroup | VisualLeaf;

/** Diccionario plano `{campo: valor_esperado}` (AND de igualdades). */
export type FlatConditions = Record<string, string | number | boolean | null>;

export type ScalarValue = string | number | boolean | null;
