import { usePage } from '@inertiajs/react';
import { Info, Plus } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { ConditionBuilder } from '@/components/sam/condition-builder';
import type { ConditionFieldDef } from '@/components/sam/condition-builder';
import { RadioCard, RadioCardGroup } from '@/components/sam/radio-card-group';
import { Step } from '@/components/sam/step';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { codeFromName } from '@/lib/labels';
import { postJson, putJson } from '@/lib/sam-fetch';
import { submit } from '@/lib/submit';
import automationRoutes from '@/routes/automation';
import { HIDDEN_CONDITION_FIELDS, TRIGGER_ORDER, TRIGGERS } from './copy';
import {
    newStepDraft,
    StepEditor,
    stepDraftFrom,
    stepPayload,
    stepProblem,
} from './step-editor';
import type { StepDraft } from './step-editor';
import type { AutomationOptions, TeamTargets, WorkflowRow } from './types';

interface EditorState {
    name: string;
    description: string;
    triggerType: string;
    conditions: Record<string, unknown>;
    steps: StepDraft[];
    enabled: boolean;
}

function initialState(workflow: WorkflowRow | null): EditorState {
    if (workflow === null) {
        return {
            name: '',
            description: '',
            triggerType: 'incident_created',
            conditions: {},
            steps: [newStepDraft()],
            enabled: true,
        };
    }

    return {
        name: workflow.name,
        description: workflow.description ?? '',
        triggerType: workflow.triggerType ?? 'incident_created',
        conditions: { ...(workflow.triggerConditions ?? {}) },
        steps:
            workflow.steps.length > 0
                ? workflow.steps.map((step, index) =>
                      stepDraftFrom(
                          step,
                          workflow.stepRecipients?.[index] ?? null,
                          index,
                      ),
                  )
                : [newStepDraft()],
        enabled: workflow.isActive && workflow.status === 'active',
    };
}

interface WorkflowEditorDialogProps {
    /** `null` + open = crear; un workflow = editarlo. */
    open: boolean;
    workflow: WorkflowRow | null;
    options: AutomationOptions;
    triggerConditionFields: Record<string, ConditionFieldDef[]>;
    teamTargets: TeamTargets;
    onOpenChange: (open: boolean) => void;
}

/**
 * Editor guiado de una automatización en tres bloques: Cuándo se activa,
 * Qué hace (pasos) y A quién (dentro de cada paso). Sirve para crear y
 * para editar — incluidos disparador y pasos, que el backend acepta en el
 * update.
 */
export function WorkflowEditorDialog(props: WorkflowEditorDialogProps) {
    return (
        <Dialog
            open={props.open}
            onOpenChange={(next) => !next && props.onOpenChange(false)}
        >
            {props.open && (
                <EditorBody
                    // Remonta el formulario al cambiar de automatización.
                    key={props.workflow?.id ?? 'new'}
                    {...props}
                />
            )}
        </Dialog>
    );
}

function EditorBody({
    workflow,
    options,
    triggerConditionFields,
    teamTargets,
    onOpenChange,
}: WorkflowEditorDialogProps) {
    const teamSlug = usePage().props.currentTeam?.slug ?? null;
    const [state, setState] = useState<EditorState>(() =>
        initialState(workflow),
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [jsonError, setJsonError] = useState<string | null>(null);
    const isNew = workflow === null;

    const knownTriggers = new Set(options.triggerTypes.map((o) => o.value));
    const triggers = TRIGGER_ORDER.filter(
        (value) =>
            (knownTriggers.has(value) && TRIGGERS[value]?.available) ||
            value === state.triggerType,
    );

    const hidden = HIDDEN_CONDITION_FIELDS[state.triggerType] ?? [];
    const allFields = triggerConditionFields[state.triggerType] ?? [];
    const conditionFields = allFields.filter(
        (field) => !hidden.includes(field.key) || field.key in state.conditions,
    );
    const hasUnknownCondition = Object.keys(state.conditions).some(
        (key) => !allFields.some((field) => field.key === key),
    );

    const set = <K extends keyof EditorState>(key: K, value: EditorState[K]) =>
        setState((prev) => ({ ...prev, [key]: value }));

    const updateStep = (index: number, next: StepDraft) =>
        setState((prev) => ({
            ...prev,
            steps: prev.steps.map((step, i) => (i === index ? next : step)),
        }));

    const save = async () => {
        if (teamSlug === null || saving || jsonError !== null) {
            return;
        }

        const nextErrors: Record<string, string> = {};

        if (state.name.trim() === '') {
            nextErrors.name = 'Ponle un nombre para reconocerla en la lista.';
        }

        state.steps.forEach((step, index) => {
            const problem = stepProblem(step);

            if (problem) {
                nextErrors[`steps_json.${index}.target_reference`] = problem;
            }
        });

        if (Object.keys(nextErrors).length > 0) {
            setErrors(nextErrors);

            return;
        }

        setErrors({});
        setSaving(true);

        const common = {
            name: state.name.trim(),
            description:
                state.description.trim() === ''
                    ? null
                    : state.description.trim(),
            trigger_type: state.triggerType,
            trigger_conditions_json:
                Object.keys(state.conditions).length > 0
                    ? state.conditions
                    : null,
            steps_json: state.steps.map((step, index) =>
                stepPayload(step, index),
            ),
        };

        const result = await submit(
            isNew
                ? postJson(automationRoutes.workflows.store.url(teamSlug), {
                      ...common,
                      code: codeFromName(
                          state.name,
                          'automatizacion',
                          Date.now().toString(36),
                          40,
                      ),
                      status: state.enabled ? 'active' : 'inactive',
                      is_active: state.enabled,
                  })
                : putJson(
                      automationRoutes.workflows.update.url([
                          teamSlug,
                          workflow.id,
                      ]),
                      common,
                  ),
            isNew ? 'Automatización creada.' : 'Automatización guardada.',
        );

        setSaving(false);

        if (result.ok) {
            onOpenChange(false);
        } else {
            setErrors(result.fieldErrors);
        }
    };

    const stepError = (index: number): string | undefined =>
        Object.entries(errors).find(([field]) =>
            field.startsWith(`steps_json.${index}.`),
        )?.[1];

    const otherErrors = Object.entries(errors).filter(
        ([field]) => field !== 'name' && !/^steps_json\.\d+\./.test(field),
    );

    return (
        <DialogContent className="flex max-h-[90dvh] flex-col gap-0 p-0 sm:max-w-2xl">
            <DialogHeader className="border-b border-border px-5 py-4 text-left">
                <DialogTitle>
                    {isNew ? 'Nueva automatización' : 'Editar automatización'}
                </DialogTitle>
                <DialogDescription>
                    Elige cuándo se activa y qué debe hacer SAM por ti.
                </DialogDescription>
            </DialogHeader>

            <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto px-5 py-5">
                <section className="flex flex-col gap-3">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="wf-name">Nombre</Label>
                        <Input
                            id="wf-name"
                            value={state.name}
                            placeholder="Ej. Avisar al monitorista si hay pánico"
                            aria-invalid={Boolean(errors.name)}
                            onChange={(e) => set('name', e.target.value)}
                        />
                        <InputError message={errors.name} className="text-xs" />
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="wf-description">
                            Para qué sirve{' '}
                            <span className="font-normal text-fg-3">
                                (opcional)
                            </span>
                        </Label>
                        <Textarea
                            id="wf-description"
                            rows={2}
                            value={state.description}
                            placeholder="Una nota para tu equipo."
                            onChange={(e) => set('description', e.target.value)}
                        />
                    </div>
                </section>

                <Step step={1} title="Cuándo se activa">
                    <RadioCardGroup
                        label="Cuándo se activa"
                        className="grid gap-2 sm:grid-cols-2"
                    >
                        {triggers.map((value) => {
                            const copy = TRIGGERS[value];

                            return (
                                <RadioCard
                                    key={value}
                                    selected={state.triggerType === value}
                                    onSelect={() =>
                                        setState((prev) => ({
                                            ...prev,
                                            triggerType: value,
                                            conditions:
                                                prev.triggerType === value
                                                    ? prev.conditions
                                                    : {},
                                        }))
                                    }
                                    icon={copy.icon}
                                    label={copy.title}
                                    description={copy.help}
                                />
                            );
                        })}
                    </RadioCardGroup>

                    {(conditionFields.length > 0 || hasUnknownCondition) && (
                        <div className="flex flex-col gap-1.5 rounded-lg border border-dashed border-border p-3">
                            <span className="text-xs font-medium text-fg-2">
                                Sólo si…{' '}
                                <span className="font-normal text-fg-3">
                                    (opcional)
                                </span>
                            </span>
                            <p className="text-2xs text-fg-3">
                                Acótala a ciertos casos, por ejemplo sólo
                                incidentes de prioridad alta. Si no eliges nada,
                                aplica siempre.
                            </p>
                            <ConditionBuilder
                                variant="flat-equality"
                                fields={conditionFields}
                                allowUnknownFields={hasUnknownCondition}
                                value={state.conditions}
                                onChange={(value) => set('conditions', value)}
                                onJsonErrorChange={setJsonError}
                            />
                        </div>
                    )}
                </Step>

                <Step step={2} title="Qué hace, y a quién">
                    <ol className="flex flex-col gap-2">
                        {state.steps.map((step, index) => (
                            <StepEditor
                                key={step.key}
                                draft={step}
                                index={index}
                                total={state.steps.length}
                                actionOptions={options.actionTypes}
                                teamTargets={teamTargets}
                                error={stepError(index)}
                                onChange={(next) => updateStep(index, next)}
                                onRemove={() =>
                                    set(
                                        'steps',
                                        state.steps.filter(
                                            (_, i) => i !== index,
                                        ),
                                    )
                                }
                            />
                        ))}
                    </ol>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="self-start"
                        onClick={() =>
                            set('steps', [...state.steps, newStepDraft()])
                        }
                    >
                        <Plus size={13} />
                        Añadir otro paso
                    </Button>
                    <p className="flex items-start gap-1.5 text-2xs text-fg-3">
                        <Info
                            className="mt-px size-3 shrink-0"
                            aria-hidden="true"
                        />
                        Los pasos se hacen en orden. Si alguien toma o cierra el
                        incidente antes, los pasos que estaban esperando ya no
                        se hacen.
                    </p>
                </Step>

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

            <DialogFooter className="flex-col items-stretch gap-3 border-t border-border px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                {isNew ? (
                    <label className="flex items-center gap-2 text-xs text-fg-2">
                        <Switch
                            checked={state.enabled}
                            onCheckedChange={(value) => set('enabled', value)}
                            aria-label="Encenderla al guardar"
                        />
                        Encenderla al guardar
                    </label>
                ) : (
                    <span className="hidden sm:block" />
                )}
                <div className="flex justify-end gap-2">
                    <Button
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        disabled={saving}
                    >
                        Cancelar
                    </Button>
                    <Button
                        onClick={save}
                        disabled={saving || jsonError !== null}
                    >
                        {saving
                            ? 'Guardando…'
                            : isNew
                              ? 'Crear automatización'
                              : 'Guardar cambios'}
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    );
}
