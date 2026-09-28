import type { ConditionFieldDef } from '@/components/sam/condition-builder';

export interface DecisionRuleRow {
    id: number;
    code: string;
    name: string;
    description: string | null;
    scope: string | null;
    priority: number;
    conditions: Record<string, unknown> | null;
    outcomeCode: string | null;
    outcomeLabel: string | null;
    outcomeId: number | null;
    stopProcessing: boolean;
    isActive: boolean;
    isGlobal: boolean;
    rulesetId: number;
    rulesetCode: string | null;
    /** Posición real en la que el motor la revisa (1 = primera); null si no se revisa. */
    evaluationOrder: number | null;
    /** false = pertenece a un conjunto de reglas que el motor no usa para este equipo. */
    inEffectiveRuleset: boolean;
}

export interface MappingRuleRow {
    id: number;
    providerId: number;
    provider: string | null;
    externalEventType: string;
    hasConditions: boolean;
    conditions: Record<string, unknown> | null;
    mappedEventTypeId: number;
    mappedEventType: string | null;
    mappedSeverity: string | null;
    effectiveSeverityCode: string | null;
    severityFromType: boolean;
    mappedSeverityId: number | null;
    priority: number;
    isActive: boolean;
}

export interface Option {
    value: string;
    label: string;
}

export interface OutcomeOption {
    id: number;
    code: string;
    name: string;
    label: string;
}

export interface RulesetOption {
    id: number;
    code: string;
    name: string;
    isDefault: boolean;
    isGlobal: boolean;
}

export interface DecisionSummary {
    total: number;
    active: number;
    own: number;
    platform: number;
    byOutcome: Record<string, number>;
}

export interface MappingSummary {
    total: number;
    active: number;
    conditional: number;
}

export interface MappingOptions {
    providers: Option[];
    eventTypes: Option[];
    severities: Option[];
    categories: Option[];
}

export interface RulesPageProps {
    decisionRules: DecisionRuleRow[];
    decisionSummary: DecisionSummary;
    rulesets: RulesetOption[];
    outcomes: OutcomeOption[];
    scopes: Option[];
    mappingRules: MappingRuleRow[];
    mappingSummary: MappingSummary;
    mappingOptions: MappingOptions;
    conditionFields: ConditionFieldDef[];
    canManageDecisionRules: boolean;
    // Las traducciones de alertas son globales de plataforma: sólo super-admin.
    canManageMappingRules: boolean;
    canManageOverrides: boolean;
}

/** Agrupación de resultados que usan el resumen y el filtro. */
export type OutcomeGroup = 'incident' | 'review' | 'alert' | 'quiet' | 'ai';
