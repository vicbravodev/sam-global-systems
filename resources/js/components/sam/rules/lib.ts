import type { ConditionFieldDef } from '@/components/sam/condition-builder';
import { formatNumber } from '@/lib/format';
import { humanizeCode } from '@/lib/labels';
import type { SubmitOptions } from '@/lib/submit';
import type { Tone } from '@/lib/tone';
import type { DecisionRuleRow, MappingRuleRow, OutcomeGroup } from './types';

// ---- Envío ----

/** Mensajes de `submit` para las reglas. */
export const RULE_SUBMIT: SubmitOptions = {
    errorMessage: 'No se pudo guardar la regla.',
};

// ---- Orden de revisión ----

/** 1 → "1.ª", 2 → "2.ª"… (regla es femenino). */
export function ordinal(position: number): string {
    return `${position}.ª`;
}

interface OrderCandidate {
    id: number | null;
    priority: number;
}

/**
 * Posición que tendría una regla con `draft` dentro de las reglas activas que
 * el motor revisa, con el mismo orden que el backend (prioridad desc, id asc;
 * una regla nueva tiene el id más alto).
 */
export function previewPosition(
    others: DecisionRuleRow[],
    draft: OrderCandidate,
): number {
    const list: OrderCandidate[] = [
        ...others.map((rule) => ({ id: rule.id, priority: rule.priority })),
        draft,
    ];

    list.sort((a, b) => {
        if (b.priority !== a.priority) {
            return b.priority - a.priority;
        }

        return (
            (a.id ?? Number.MAX_SAFE_INTEGER) -
            (b.id ?? Number.MAX_SAFE_INTEGER)
        );
    });

    return list.indexOf(draft) + 1;
}

/**
 * Prioridad numérica para colocar una regla "antes que todas" o "después de"
 * otra. Si no hay hueco entre dos prioridades, empata con la anterior y el
 * desempate por antigüedad decide (la vista previa lo muestra).
 */
export function priorityForPlacement(
    anchors: DecisionRuleRow[],
    placement: string,
): number {
    if (anchors.length === 0) {
        return 100;
    }

    if (placement === 'first') {
        return Math.min(255, anchors[0].priority + 1);
    }

    const index = anchors.findIndex((rule) => `after:${rule.id}` === placement);

    if (index === -1) {
        return anchors[anchors.length - 1].priority;
    }

    const current = anchors[index].priority;
    const next = anchors[index + 1];

    if (!next) {
        return Math.max(0, current - 1);
    }

    if (current - next.priority >= 2) {
        return Math.floor((current + next.priority) / 2);
    }

    return current;
}

// ---- Resultados ----

export function outcomeGroup(code: string | null): OutcomeGroup {
    switch (code) {
        case 'INCIDENT':
        case 'ESCALATE':
            return 'incident';
        case 'REQUIRE_HUMAN_REVIEW':
            return 'review';
        case 'ALERT':
            return 'alert';
        case 'IGNORE':
        case 'LOG_ONLY':
            return 'quiet';
        default:
            return 'ai';
    }
}

/** Clases de color semántico por resultado (siempre acompañadas de texto). */
export const OUTCOME_TONE: Record<OutcomeGroup, Tone> = {
    incident: 'critical',
    review: 'warn',
    alert: 'info',
    quiet: 'neutral',
    ai: 'primary',
};

/** Qué significa cada resultado, para el editor. */
export const OUTCOME_HELP: Record<string, string> = {
    INCIDENT: 'Se abre un incidente en la bandeja para que alguien lo atienda.',
    ESCALATE:
        'Se abre un incidente y se escala de inmediato a los responsables.',
    REQUIRE_HUMAN_REVIEW:
        'Una persona revisa el evento antes de decidir si es un incidente.',
    ALERT: 'Aparece en la bandeja con urgencia baja.',
    LOG_ONLY: 'Queda guardado en el historial, sin avisar a nadie.',
    IGNORE: 'Se descarta sin avisar a nadie.',
};

/** Orden de presentación de resultados en el editor (de más a menos grave). */
export const OUTCOME_ORDER = [
    'ESCALATE',
    'INCIDENT',
    'REQUIRE_HUMAN_REVIEW',
    'ALERT',
    'LOG_ONLY',
    'IGNORE',
];

// ---- Condiciones como frase ----

export type SentenceNode =
    | { kind: 'group'; logic: 'all' | 'any'; children: SentenceNode[] }
    | { kind: 'leaf'; subject: string; predicate: string | null };

const OPERATOR_PHRASES: Record<string, string> = {
    eq: 'es',
    neq: 'no es',
    gt: 'mayor que',
    gte: 'de al menos',
    lt: 'menor que',
    lte: 'de como máximo',
    in: 'es',
    not_in: 'no es',
    contains: 'contiene',
};

function cleanLabel(label: string): string {
    return label.replace(/[¿?]/g, '').trim();
}

function formatScalar(value: unknown, field?: ConditionFieldDef): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'sí' : 'no';
    }

    if (typeof value === 'number') {
        return formatNumber(value);
    }

    const text = String(value);
    const option = field?.options.find((item) => item.value === text);

    if (option) {
        return option.label;
    }

    return field?.type === 'string' ? `«${text}»` : humanizeCode(text);
}

function joinList(values: string[], connector: 'o' | 'ni'): string {
    if (values.length <= 1) {
        return values[0] ?? '—';
    }

    return `${values.slice(0, -1).join(', ')} ${connector} ${values[values.length - 1]}`;
}

function leafNode(
    node: Record<string, unknown>,
    fields: ConditionFieldDef[],
): SentenceNode {
    const key = String(node.field ?? '');
    const operator = String(node.operator ?? 'eq');
    const field = fields.find((item) => item.key === key);
    const subject = cleanLabel(field?.label ?? humanizeCode(key));
    const value = node.value;

    if (operator === 'is_null') {
        return { kind: 'leaf', subject, predicate: 'sin dato' };
    }

    if (operator === 'is_not_null') {
        return { kind: 'leaf', subject, predicate: 'con dato' };
    }

    if (field?.type === 'boolean' && typeof value === 'boolean') {
        const positive = operator === 'neq' ? !value : value;

        return positive
            ? { kind: 'leaf', subject, predicate: null }
            : { kind: 'leaf', subject, predicate: 'no' };
    }

    if (Array.isArray(value)) {
        const items = value.map((item) => formatScalar(item, field));

        return {
            kind: 'leaf',
            subject,
            predicate: `${OPERATOR_PHRASES[operator] ?? operator} ${joinList(items, operator === 'not_in' ? 'ni' : 'o')}`,
        };
    }

    return {
        kind: 'leaf',
        subject,
        predicate: `${OPERATOR_PHRASES[operator] ?? operator} ${formatScalar(value, field)}`,
    };
}

function toNode(
    node: unknown,
    fields: ConditionFieldDef[],
): SentenceNode | null {
    if (node === null || typeof node !== 'object' || Array.isArray(node)) {
        return null;
    }

    const record = node as Record<string, unknown>;

    for (const logic of ['all', 'any'] as const) {
        if (Array.isArray(record[logic])) {
            const children = (record[logic] as unknown[])
                .map((child) => toNode(child, fields))
                .filter((child): child is SentenceNode => child !== null);

            return { kind: 'group', logic, children };
        }
    }

    if (typeof record.field === 'string') {
        return leafNode(record, fields);
    }

    return null;
}

/** Una condición suelta en español (la usa el probador por cada chequeo). */
export function describeCondition(
    field: string,
    operator: string,
    value: unknown,
    fields: ConditionFieldDef[],
): { subject: string; predicate: string | null } {
    const node = leafNode({ field, operator, value }, fields);

    return node.kind === 'leaf'
        ? { subject: node.subject, predicate: node.predicate }
        : { subject: field, predicate: null };
}

/** Valor real de un evento, con la etiqueta de la opción si existe. */
export function describeValue(
    field: string,
    value: unknown,
    fields: ConditionFieldDef[],
): string {
    if (value === null || value === undefined) {
        return 'sin dato';
    }

    if (Array.isArray(value)) {
        return value
            .map((item) => describeValue(field, item, fields))
            .join(', ');
    }

    return formatScalar(
        value,
        fields.find((item) => item.key === field),
    );
}

/** Condiciones JSON → árbol de frase; null = sin condiciones (aplica siempre). */
export function conditionsToSentence(
    conditions: Record<string, unknown> | null,
    fields: ConditionFieldDef[],
): SentenceNode | null {
    if (!conditions || Object.keys(conditions).length === 0) {
        return null;
    }

    const node = toNode(conditions, fields);

    if (node?.kind === 'group' && node.children.length === 0) {
        return null;
    }

    return node;
}

// ---- Alta de reglas ----

export function randomSuffix(): string {
    return Math.random().toString(36).slice(2, 6);
}

/** El ámbito es descriptivo: por tipo de evento si la regla filtra por tipo. */
export function scopeForConditions(
    conditions: Record<string, unknown>,
): string {
    return JSON.stringify(conditions).includes('"event_type_code"')
        ? 'event_type'
        : 'tenant';
}

// ---- Traducción de alertas ----

/** "HeavySpeeding" → "Heavy Speeding" (nombre tal como lo muestra Samsara). */
function splitCamel(value: string): string {
    return value
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .replace(/[_.]+/g, ' ')
        .trim();
}

export interface MappingSource {
    /** Nombre de la alerta como la reconoce el operador. */
    title: string;
    /** Aclaración secundaria (tipo de alerta o condición). */
    detail: string | null;
}

const NAME_KEYS = /(description|name|label)$/i;

export function mappingSource(rule: MappingRuleRow): MappingSource {
    const base = splitCamel(rule.externalEventType);
    const entries = Object.entries(rule.conditions ?? {});

    // Alertas configuradas en Samsara (AlertIncident): el nombre real de la
    // alerta viaja en una condición ("Panic Button").
    if (
        entries.length === 1 &&
        NAME_KEYS.test(entries[0][0]) &&
        typeof entries[0][1] === 'string'
    ) {
        return {
            title: entries[0][1] as string,
            detail: `Alerta configurada (${base})`,
        };
    }

    if (entries.length > 0) {
        return {
            title: base,
            detail: `Solo si ${entries
                .map(([key, value]) => `${key} = ${String(value)}`)
                .join(' y ')}`,
        };
    }

    return { title: base, detail: null };
}
