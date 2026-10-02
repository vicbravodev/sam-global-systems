import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { ConditionBuilder } from '@/components/sam/condition-builder';
import type { ConditionFieldDef } from '@/components/sam/condition-builder';
import { FormField } from '@/components/sam/form-field';
import { RadioCard, RadioCardGroup } from '@/components/sam/radio-card-group';
import { Step } from '@/components/sam/step';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { decisionOutcomeEffectLabel } from '@/lib/labels';
import { postJson, putJson } from '@/lib/sam-fetch';
import { cn } from '@/lib/utils';
import {
    codeFromName,
    ordinal,
    OUTCOME_HELP,
    OUTCOME_ORDER,
    OUTCOME_TONE,
    outcomeGroup,
    previewPosition,
    priorityForPlacement,
    randomSuffix,
    scopeForConditions,
    submitRuleChange,
    useRulesBase,
} from './lib';
import { ReadOnlyNote } from './rule-editor-parts';
import { RuleSentence } from './rule-sentence';
import { RuleTester } from './rule-tester';
import type { DecisionRuleRow, OutcomeOption, RulesetOption } from './types';

const NO_OUTCOME = 'none';

const DEFAULT_CONDITIONS = {
    all: [{ field: 'event_type_code', operator: 'eq', value: '' }],
};

interface DecisionRuleSheetProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** null = regla nueva. */
    rule: DecisionRuleRow | null;
    rules: DecisionRuleRow[];
    fields: ConditionFieldDef[];
    outcomes: OutcomeOption[];
    rulesets: RulesetOption[];
    canManage: boolean;
}

/**
 * Editor guiado de una regla de decisión, en panel lateral: cuándo aplica,
 * qué debe pasar, en qué orden se revisa y cómo se llama — con la frase de
 * la regla y el probador a la vista. El identificador interno se genera solo.
 */
export function DecisionRuleSheet(props: DecisionRuleSheetProps) {
    return (
        <Sheet open={props.open} onOpenChange={props.onOpenChange}>
            <SheetContent className="w-full gap-0 p-0 sm:max-w-2xl">
                {props.open && (
                    <DecisionRuleForm
                        key={props.rule?.id ?? 'new'}
                        {...props}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function DecisionRuleForm({
    onOpenChange,
    rule,
    rules,
    fields,
    outcomes,
    rulesets,
    canManage,
}: DecisionRuleSheetProps) {
    const base = useRulesBase();
    const isNew = rule === null;
    const editable = canManage && (isNew || !rule.isGlobal);

    // Las reglas que el motor revisa hoy, en orden, sin la que se edita.
    const anchors = useMemo(
        () =>
            rules
                .filter(
                    (item) =>
                        item.evaluationOrder !== null && item.id !== rule?.id,
                )
                .sort(
                    (a, b) =>
                        (a.evaluationOrder ?? 0) - (b.evaluationOrder ?? 0),
                ),
        [rules, rule],
    );

    const initialPriority =
        rule?.priority ??
        priorityForPlacement(
            anchors,
            anchors.length > 0
                ? `after:${anchors[anchors.length - 1].id}`
                : 'first',
        );

    const [suffix] = useState(randomSuffix);
    const [conditions, setConditions] = useState<Record<string, unknown>>(
        rule?.conditions ?? DEFAULT_CONDITIONS,
    );
    const [outcomeId, setOutcomeId] = useState<string>(
        rule?.outcomeId != null ? String(rule.outcomeId) : NO_OUTCOME,
    );
    const [name, setName] = useState(rule?.name ?? '');
    const [description, setDescription] = useState(rule?.description ?? '');
    const [priority, setPriority] = useState<number>(initialPriority);
    const [stopProcessing, setStopProcessing] = useState(
        rule?.stopProcessing ?? false,
    );
    const [jsonError, setJsonError] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const outcomeCode =
        outcomes.find((item) => String(item.id) === outcomeId)?.code ?? null;

    const position = previewPosition(anchors, {
        id: rule?.id ?? null,
        priority,
    });
    const total = anchors.length + 1;

    const placement =
        position <= 1 ? 'first' : `after:${anchors[position - 2]?.id}`;

    const outcomeChoices = [
        ...OUTCOME_ORDER.map((code) =>
            outcomes.find((item) => item.code === code),
        ).filter((item): item is OutcomeOption => item !== undefined),
    ];

    const save = async () => {
        if (base === null || saving || !editable) {
            return;
        }

        if (jsonError !== null) {
            setErrors({
                conditions_json: 'El texto avanzado no es válido: corrígelo.',
            });

            return;
        }

        if (JSON.stringify(conditions).includes('"value":""')) {
            setErrors({
                conditions_json: 'Completa el valor de cada condición.',
            });

            return;
        }

        if (name.trim() === '') {
            setErrors({ name: 'Ponle un nombre para reconocerla.' });

            return;
        }

        setErrors({});
        setSaving(true);

        const body = {
            name: name.trim(),
            description: description.trim() === '' ? null : description,
            priority,
            conditions_json: conditions,
            outcome_override:
                outcomeId === NO_OUTCOME ? null : Number(outcomeId),
            stop_processing: stopProcessing,
        };

        let result;

        if (isNew) {
            const ruleset =
                rulesets.find((set) => !set.isGlobal && set.isDefault) ??
                rulesets[0];

            if (!ruleset) {
                setErrors({
                    ruleset_id:
                        'Tu cuenta aún no tiene un conjunto de reglas. Contacta a soporte.',
                });
                setSaving(false);

                return;
            }

            result = await submitRuleChange(
                postJson(`${base}/decision`, {
                    ...body,
                    ruleset_id: ruleset.id,
                    code: codeFromName(name, suffix),
                    scope: scopeForConditions(conditions),
                    is_active: true,
                }),
                'Regla creada y encendida.',
            );
        } else {
            result = await submitRuleChange(
                putJson(`${base}/decision/${rule.id}`, body),
                'Regla guardada.',
            );
        }

        setSaving(false);

        if (result.ok) {
            onOpenChange(false);
        } else {
            setErrors(result.fieldErrors);
        }
    };

    const otherErrors = Object.entries(errors).filter(
        ([field]) =>
            ![
                'name',
                'conditions_json',
                'priority',
                'outcome_override',
            ].includes(field),
    );

    return (
        <>
            <SheetHeader className="shrink-0 border-b border-border px-5 py-4 pr-12">
                <SheetTitle className="text-base">
                    {isNew
                        ? 'Nueva regla'
                        : editable
                          ? 'Editar regla'
                          : rule.name}
                </SheetTitle>
                <SheetDescription className="text-xs">
                    Una regla dice qué hacer cuando un evento cumple ciertas
                    condiciones.
                </SheetDescription>
            </SheetHeader>

            <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto px-5 py-5">
                {!editable && rule && (
                    <ReadOnlyNote>
                        {rule.isGlobal
                            ? 'Esta regla la mantiene SAM y aplica a todas las cuentas. Puedes verla y probarla, pero no cambiarla.'
                            : 'No tienes permiso para cambiar reglas. Puedes verla y probarla.'}
                    </ReadOnlyNote>
                )}

                <div className="rounded-md border border-border bg-surface-1 px-3 py-2.5">
                    <span className="sam-caps mb-1.5 block">
                        Así se lee la regla
                    </span>
                    <RuleSentence
                        conditions={conditions}
                        fields={fields}
                        outcomeCode={outcomeCode}
                    />
                </div>

                <Step
                    step={1}
                    title="¿Cuándo aplica?"
                    help="Elige qué debe cumplir el evento. Puedes sumar varias condiciones: «todas» exige que se cumplan todas; «alguna», con una basta."
                >
                    <ConditionBuilder
                        variant="tree"
                        fields={fields}
                        value={conditions}
                        onChange={(next) => {
                            setConditions(next);
                            setErrors({});
                        }}
                        onJsonErrorChange={setJsonError}
                        disabled={!editable}
                    />
                    <InputError
                        message={errors.conditions_json}
                        className="text-xs"
                    />
                </Step>

                <Step
                    step={2}
                    title="¿Qué debe pasar?"
                    help="Lo que SAM hará con el evento cuando la regla se cumpla."
                >
                    <RadioCardGroup
                        label="Resultado de la regla"
                        className="grid grid-cols-1 gap-2 sm:grid-cols-2"
                    >
                        {outcomeChoices.map((outcome) => (
                            <OutcomeChoice
                                key={outcome.id}
                                code={outcome.code}
                                selected={outcomeId === String(outcome.id)}
                                disabled={!editable}
                                onSelect={() =>
                                    setOutcomeId(String(outcome.id))
                                }
                            />
                        ))}
                        <OutcomeChoice
                            code={null}
                            selected={outcomeId === NO_OUTCOME}
                            disabled={!editable}
                            onSelect={() => setOutcomeId(NO_OUTCOME)}
                        />
                    </RadioCardGroup>
                    <InputError
                        message={errors.outcome_override}
                        className="text-xs"
                    />
                </Step>

                <Step
                    step={3}
                    title="¿En qué orden se revisa?"
                    help="Las reglas se revisan de arriba abajo. Pon primero las más importantes, como las de seguridad."
                >
                    <FormField
                        label="Revisar esta regla"
                        htmlFor="rule-placement"
                        error={errors.priority}
                        size="sm"
                    >
                        <Select
                            value={placement}
                            disabled={!editable}
                            onValueChange={(value) =>
                                setPriority(
                                    priorityForPlacement(anchors, value),
                                )
                            }
                        >
                            <SelectTrigger
                                id="rule-placement"
                                className="h-9 w-full sm:w-96"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="first">
                                    Antes que todas
                                </SelectItem>
                                {anchors.map((anchor) => (
                                    <SelectItem
                                        key={anchor.id}
                                        value={`after:${anchor.id}`}
                                    >
                                        Después de «{anchor.name}»
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-fg-3">
                            Se revisará en{' '}
                            <span className="font-medium text-fg-1">
                                {ordinal(position)} posición
                            </span>{' '}
                            de {total}
                            {rule && !rule.isActive
                                ? ' cuando la enciendas.'
                                : '.'}
                        </p>
                    </FormField>

                    <label
                        htmlFor="rule-stop"
                        className="flex items-start gap-3 rounded-md border border-border px-3 py-2.5"
                    >
                        <Switch
                            id="rule-stop"
                            checked={stopProcessing}
                            onCheckedChange={setStopProcessing}
                            disabled={!editable}
                            className="mt-0.5"
                        />
                        <span className="flex flex-col gap-0.5">
                            <span className="text-sm text-fg-1">
                                Si se cumple, no revisar las siguientes
                            </span>
                            <span className="text-xs text-fg-3">
                                Útil para reglas de seguridad: cuando esta se
                                cumple, las reglas de abajo ya no cuentan.
                            </span>
                        </span>
                    </label>
                </Step>

                <Step step={4} title="¿Cómo se llama?">
                    <FormField
                        label="Nombre"
                        htmlFor="rule-name"
                        error={errors.name}
                        size="sm"
                    >
                        <Input
                            id="rule-name"
                            value={name}
                            maxLength={200}
                            placeholder="Ej. Pánico en base → revisión"
                            aria-invalid={Boolean(errors.name)}
                            disabled={!editable}
                            onChange={(e) => setName(e.target.value)}
                        />
                    </FormField>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="rule-description" className="text-xs">
                            Para qué sirve{' '}
                            <span className="text-fg-3">(opcional)</span>
                        </Label>
                        <Textarea
                            id="rule-description"
                            value={description}
                            rows={2}
                            placeholder="Explica a tu equipo por qué existe esta regla."
                            disabled={!editable}
                            onChange={(e) => setDescription(e.target.value)}
                        />
                    </div>
                </Step>

                {base !== null && (
                    <Step
                        step={5}
                        title="Pruébala"
                        help="Comprueba si la regla se habría cumplido con el último evento que evaluó SAM. No cambia nada."
                    >
                        <RuleTester
                            endpoint={`${base}/test-decision`}
                            payload={() => ({ conditions_json: conditions })}
                            fields={fields}
                            outcomeCode={outcomeCode}
                        />
                    </Step>
                )}

                <details className="group text-xs text-fg-3">
                    <summary className="cursor-pointer select-none hover:text-fg-2">
                        Detalles técnicos
                    </summary>
                    <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                        <dt>Identificador</dt>
                        <dd className="font-mono break-all text-fg-2">
                            {rule?.code ??
                                codeFromName(name || 'regla', suffix)}
                        </dd>
                        <dt>Prioridad numérica</dt>
                        <dd className="font-mono text-fg-2">
                            {priority} (0–255, mayor se revisa antes)
                        </dd>
                    </dl>
                </details>

                {otherErrors.length > 0 && (
                    <ul className="flex flex-col gap-0.5">
                        {otherErrors.map(([field, message]) => (
                            <li key={field}>
                                <InputError
                                    message={message}
                                    className="text-xs"
                                />
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <SheetFooter className="shrink-0 flex-row justify-end border-t border-border px-5 py-3">
                <Button variant="ghost" onClick={() => onOpenChange(false)}>
                    {editable ? 'Cancelar' : 'Cerrar'}
                </Button>
                {editable && (
                    <Button onClick={save} disabled={saving}>
                        {saving
                            ? 'Guardando…'
                            : isNew
                              ? 'Crear regla'
                              : 'Guardar cambios'}
                    </Button>
                )}
            </SheetFooter>
        </>
    );
}

function OutcomeChoice({
    code,
    selected,
    disabled,
    onSelect,
}: {
    code: string | null;
    selected: boolean;
    disabled: boolean;
    onSelect: () => void;
}) {
    const tone = OUTCOME_TONE[outcomeGroup(code)];

    return (
        <RadioCard
            selected={selected}
            disabled={disabled}
            onSelect={onSelect}
            className={cn(
                'px-3 py-2',
                selected && 'ring-1 ring-primary',
                disabled && !selected && 'opacity-60',
            )}
            label={
                <>
                    <span
                        className={cn('size-2 shrink-0 rounded-full', tone.dot)}
                        aria-hidden="true"
                    />
                    {decisionOutcomeEffectLabel(code)}
                </>
            }
            description={
                code === null
                    ? 'La regla no fija el resultado: lo decide la IA.'
                    : OUTCOME_HELP[code]
            }
        />
    );
}
