import { Plus, Scale, X } from 'lucide-react';
import { lazy, Suspense, useMemo, useState } from 'react';
import type { ConditionFieldDef } from '@/components/sam/condition-builder';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { deleteJson, putJson } from '@/lib/sam-fetch';
import { submit } from '@/lib/submit';
import { DecisionRuleCard } from './decision-rule-card';
import { outcomeGroup, RULE_SUBMIT, useRulesBase } from './lib';
import { RuleTestDialog } from './rule-tester';
import type { DecisionRuleRow, OutcomeOption, RulesetOption } from './types';

// The editor (condition builder + comboboxes) loads on its first opening.
const DecisionRuleSheet = lazy(() =>
    import('./decision-rule-sheet').then((module) => ({
        default: module.DecisionRuleSheet,
    })),
);

/** Filtros que aplica la franja de resumen. */
export type DecisionFilter = 'active' | 'incident' | 'review' | 'other' | 'off';

const DECISION_FILTER_LABELS: Record<DecisionFilter, string> = {
    active: 'encendidas',
    incident: 'abren un incidente',
    review: 'piden revisión de una persona',
    other: 'solo avisan o descartan',
    off: 'apagadas',
};

function matchesDecisionFilter(
    rule: DecisionRuleRow,
    filter: DecisionFilter | null,
): boolean {
    if (filter === null) {
        return true;
    }

    if (filter === 'off') {
        return !rule.isActive;
    }

    if (!rule.isActive) {
        return false;
    }

    const group = outcomeGroup(rule.outcomeCode);

    switch (filter) {
        case 'incident':
            return group === 'incident';
        case 'review':
            return group === 'review';
        case 'other':
            return group === 'alert' || group === 'quiet';
        default:
            return true;
    }
}

const HOW_IT_WORKS = [
    {
        lead: 'En orden.',
        text: 'Cada evento se compara con las reglas encendidas, de la 1.ª hacia abajo.',
    },
    {
        lead: 'Si ninguna se cumple,',
        text: 'decide la IA según lo que vio en el evento.',
    },
    {
        lead: 'Por seguridad,',
        text: 'un evento crítico (pánico, colisión) siempre abre un incidente, salvo que una regla pida revisión de una persona.',
    },
];

/** Cómo se aplican las reglas: tres tarjetas en escritorio, plegado en móvil. */
function HowRulesWork() {
    const items = HOW_IT_WORKS.map((item) => (
        <li
            key={item.lead}
            className="rounded-md border border-border bg-surface-1 px-3 py-2"
        >
            <span className="font-semibold text-fg-1">{item.lead}</span>{' '}
            {item.text}
        </li>
    ));

    return (
        <>
            <ul className="hidden grid-cols-3 gap-2 text-xs text-fg-2 md:grid">
                {items}
            </ul>
            <details className="rounded-md border border-border bg-surface-1 px-3 py-2 text-xs text-fg-2 md:hidden">
                <summary className="cursor-pointer font-medium text-fg-1 select-none">
                    ¿Cómo se aplican las reglas?
                </summary>
                <ul className="mt-2 flex flex-col gap-1.5">
                    {HOW_IT_WORKS.map((item) => (
                        <li key={item.lead}>
                            <span className="font-semibold text-fg-1">
                                {item.lead}
                            </span>{' '}
                            {item.text}
                        </li>
                    ))}
                </ul>
            </details>
        </>
    );
}

interface DecisionRulesTabProps {
    rules: DecisionRuleRow[];
    rulesets: RulesetOption[];
    outcomes: OutcomeOption[];
    fields: ConditionFieldDef[];
    canManage: boolean;
    filter: DecisionFilter | null;
    onClearFilter: () => void;
    creating: boolean;
    onCreatingChange: (creating: boolean) => void;
}

export function DecisionRulesTab({
    rules,
    rulesets,
    outcomes,
    fields,
    canManage,
    filter,
    onClearFilter,
    creating,
    onCreatingChange,
}: DecisionRulesTabProps) {
    const base = useRulesBase();
    const [openRuleId, setOpenRuleId] = useState<number | null>(null);
    const [testRule, setTestRule] = useState<DecisionRuleRow | null>(null);
    const [deleteRule, setDeleteRule] = useState<DecisionRuleRow | null>(null);
    const [togglingId, setTogglingId] = useState<number | null>(null);

    // Orden real primero (1.ª, 2.ª…), luego las apagadas o fuera de uso.
    const ordered = useMemo(
        () =>
            [...rules].sort((a, b) => {
                if (a.evaluationOrder !== null && b.evaluationOrder !== null) {
                    return a.evaluationOrder - b.evaluationOrder;
                }

                if (a.evaluationOrder !== null) {
                    return -1;
                }

                if (b.evaluationOrder !== null) {
                    return 1;
                }

                return b.priority - a.priority || a.id - b.id;
            }),
        [rules],
    );

    const visible = ordered.filter((rule) =>
        matchesDecisionFilter(rule, filter),
    );
    const hasPlatform = rules.some((rule) => rule.isGlobal);
    const hasOwn = rules.some((rule) => !rule.isGlobal);
    const openRule = rules.find((rule) => rule.id === openRuleId) ?? null;
    const sheetOpen = creating || openRule !== null;
    // Mounted on first opening and kept, so closing still animates.
    const [sheetMounted, setSheetMounted] = useState(false);

    if (sheetOpen && !sheetMounted) {
        setSheetMounted(true);
    }

    const toggle = async (rule: DecisionRuleRow, active: boolean) => {
        if (base === null || togglingId !== null) {
            return;
        }

        setTogglingId(rule.id);
        await submit(
            putJson(`${base}/decision/${rule.id}`, { is_active: active }),
            active ? 'Regla encendida.' : 'Regla apagada.',
            RULE_SUBMIT,
        );
        setTogglingId(null);
    };

    const remove = async () => {
        if (base === null || deleteRule === null) {
            return;
        }

        const result = await submit(
            deleteJson(`${base}/decision/${deleteRule.id}`),
            'Regla eliminada.',
            RULE_SUBMIT,
        );

        if (result.ok) {
            setDeleteRule(null);
        }
    };

    return (
        <div className="flex flex-col gap-4 px-4 py-4 sm:px-5">
            <HowRulesWork />

            {filter !== null && (
                <div className="flex flex-wrap items-center gap-2 text-xs text-fg-2">
                    Mostrando reglas que{' '}
                    {filter === 'active' || filter === 'off' ? 'están ' : ''}
                    <span className="font-medium text-fg-1">
                        {DECISION_FILTER_LABELS[filter]}
                    </span>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 px-2 text-xs"
                        onClick={onClearFilter}
                    >
                        <X className="size-3" />
                        Ver todas
                    </Button>
                </div>
            )}

            {rules.length === 0 ? (
                <EmptyState
                    icon={Scale}
                    title="Todavía no tienes reglas"
                    description="Sin reglas, la IA decide qué hacer con cada evento. Crea una para fijar tú el resultado, por ejemplo: «Si llega un botón de pánico → abrir un incidente»."
                    action={
                        canManage ? (
                            <Button
                                size="sm"
                                onClick={() => onCreatingChange(true)}
                            >
                                <Plus className="size-3.5" />
                                Crear la primera regla
                            </Button>
                        ) : undefined
                    }
                    className="rounded-lg border border-dashed border-border"
                />
            ) : visible.length === 0 ? (
                <EmptyState
                    icon={Scale}
                    title="Ninguna regla con este filtro"
                    description="Prueba con otro resumen o vuelve a ver todas."
                    action={
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={onClearFilter}
                        >
                            Ver todas
                        </Button>
                    }
                    className="rounded-lg border border-dashed border-border"
                />
            ) : (
                <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border bg-background">
                    {visible.map((rule) => (
                        <DecisionRuleCard
                            key={rule.id}
                            rule={rule}
                            fields={fields}
                            editable={canManage && !rule.isGlobal}
                            showOrigin={hasPlatform && hasOwn}
                            onOpen={() => setOpenRuleId(rule.id)}
                            onTest={() => setTestRule(rule)}
                            onToggle={(active) => void toggle(rule, active)}
                            onDelete={() => setDeleteRule(rule)}
                            toggling={togglingId === rule.id}
                        />
                    ))}
                </ul>
            )}

            {sheetMounted && (
                <Suspense fallback={null}>
                    <DecisionRuleSheet
                        open={sheetOpen}
                        onOpenChange={(open) => {
                            if (!open) {
                                onCreatingChange(false);
                                setOpenRuleId(null);
                            }
                        }}
                        rule={creating ? null : openRule}
                        rules={rules}
                        fields={fields}
                        outcomes={outcomes}
                        rulesets={rulesets}
                        canManage={canManage}
                    />
                </Suspense>
            )}

            {base !== null && (
                <RuleTestDialog
                    open={testRule !== null}
                    onOpenChange={(open) => !open && setTestRule(null)}
                    title={testRule?.name ?? ''}
                    endpoint={`${base}/test-decision`}
                    payload={() => ({
                        conditions_json: testRule?.conditions ?? {},
                    })}
                    fields={fields}
                    outcomeCode={testRule?.outcomeCode ?? null}
                />
            )}

            <ConfirmDialog
                open={deleteRule !== null}
                onOpenChange={(open) => !open && setDeleteRule(null)}
                title={`¿Eliminar «${deleteRule?.name ?? ''}»?`}
                description="La regla deja de aplicarse y desaparece de la lista. Las decisiones que ya tomó siguen en el historial. Si solo quieres pausarla, apágala con el interruptor."
                confirmLabel="Eliminar regla"
                onConfirm={remove}
            />
        </div>
    );
}
