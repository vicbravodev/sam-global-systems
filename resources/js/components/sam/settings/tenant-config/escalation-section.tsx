import { Plus, TrendingUp, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ConditionBuilder } from '@/components/sam/condition-builder';
import type { ConditionFieldDef } from '@/components/sam/condition-builder';
import { FormCard } from '@/components/sam/field';
import { ChipToggle, StatePill } from '@/components/sam/settings/controls';
import { SettingsSection } from '@/components/sam/settings/settings-page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { humanizeCode } from '@/lib/labels';
import { postJson, putJson } from '@/lib/sam-fetch';
import {
    JsonField,
    parseJson,
    providedOr,
    submit,
    useTeamBase,
} from './shared';
import type { EscalationConfigRow, Option } from './types';

const ESCALATION_TYPE_LABELS: Record<string, string> = {
    incident_critical: 'Incidentes críticos',
    incident_high: 'Incidentes de gravedad alta',
    sla_breach: 'Tiempo de respuesta vencido',
    panic: 'Botón de pánico',
};

function escalationTitle(type: string): string {
    return ESCALATION_TYPE_LABELS[type.toLowerCase()] ?? humanizeCode(type);
}

export function EscalationSection({
    configs,
    conditionFields,
    channelTypes,
    canManage,
}: {
    configs: EscalationConfigRow[];
    conditionFields: ConditionFieldDef[];
    channelTypes: Option[];
    canManage: boolean;
}) {
    const base = useTeamBase();
    const [saving, setSaving] = useState(false);

    const saveExisting = async (
        config: EscalationConfigRow,
        steps: unknown[],
        conditions: Record<string, unknown>,
    ) => {
        if (base === null) {
            return;
        }

        setSaving(true);
        await submit(
            putJson(`${base}/escalation/${config.id}`, {
                steps,
                trigger_conditions: conditions,
            }),
            'Escalamiento guardado.',
        );
        setSaving(false);
    };

    const createDefault = async () => {
        if (base === null) {
            return;
        }

        setSaving(true);
        await submit(
            postJson(`${base}/escalation`, {
                escalation_type: 'incident_critical',
                trigger_conditions: { priority: 'critical' },
                steps: [
                    {
                        delay_minutes: 5,
                        channels: providedOr(channelTypes, ['sms', 'voice']),
                    },
                    {
                        delay_minutes: 15,
                        channels: providedOr(channelTypes, ['sms', 'email']),
                    },
                ],
                is_active: true,
            }),
            'Escalamiento creado.',
        );
        setSaving(false);
    };

    if (configs.length === 0) {
        return (
            <SettingsSection
                title="Escalamientos"
                description="Si un incidente vence su tiempo de respuesta sin que nadie lo atienda, SAM avisa en pasos."
            >
                <FormCard>
                    <EmptyState
                        className="py-8"
                        icon={TrendingUp}
                        title="Aún no hay escalamiento"
                        description="Sin escalamiento, un incidente que vence su tiempo de respuesta sólo se queda en la bandeja. Empieza con el recomendado para incidentes críticos y ajústalo después."
                        action={
                            canManage ? (
                                <Button
                                    size="sm"
                                    onClick={() => void createDefault()}
                                    disabled={saving}
                                >
                                    Crear escalamiento de críticos
                                </Button>
                            ) : null
                        }
                    />
                </FormCard>
            </SettingsSection>
        );
    }

    return (
        <>
            {configs.map((config) => (
                <EscalationCard
                    key={config.id}
                    config={config}
                    conditionFields={conditionFields}
                    channelTypes={channelTypes}
                    canManage={canManage}
                    saving={saving}
                    onSave={saveExisting}
                />
            ))}
        </>
    );
}

interface EscalationStepDraft {
    id: number;
    delayMinutes: string;
    channels: string[];
    /** Campo heredado que el vigilante de SLA no lee; se conserva tal cual. */
    recipient: string;
    contacts: string;
    /** '' = el escalón por defecto del nivel (ResolveEscalationAudience). */
    audience: string;
    attempts: string;
    retryMinutes: string;
}

/** Escalones de ResolveEscalationAudience. */
const AUDIENCE_OPTIONS: Option[] = [
    { value: 'on_call', label: 'Persona en turno' },
    { value: 'operations', label: 'Supervisores' },
    { value: 'admins', label: 'Administradores' },
];

/** ResolveEscalationAudience::defaultFor(): 0 → en turno, 1 → supervisores, 2+ → admins. */
function defaultAudienceFor(index: number): string {
    if (index <= 0) {
        return 'on_call';
    }

    return index === 1 ? 'operations' : 'admins';
}

let escalationStepId = 0;

const KNOWN_STEP_KEYS = [
    'delay_minutes',
    'channels',
    'recipient',
    'contacts',
    'audience',
    'attempts',
    'retry_minutes',
];

/** Default de CheckIncidentAcknowledgementJob::DEFAULT_RETRY_MINUTES. */
const DEFAULT_RETRY_MINUTES = 5;

/**
 * steps_json → filas editables. Devuelve null si algún paso tiene una
 * estructura que el editor no representa (se cae al modo texto sin perder
 * datos).
 */
function parseEscalationSteps(steps: unknown[]): EscalationStepDraft[] | null {
    const drafts: EscalationStepDraft[] = [];

    for (const step of steps) {
        if (step === null || typeof step !== 'object' || Array.isArray(step)) {
            return null;
        }

        const record = step as Record<string, unknown>;

        if (Object.keys(record).some((key) => !KNOWN_STEP_KEYS.includes(key))) {
            return null;
        }

        const channels = record.channels ?? [];
        const contacts = record.contacts ?? [];
        const audience = record.audience ?? '';

        if (
            !Array.isArray(channels) ||
            channels.some((channel) => typeof channel !== 'string') ||
            !Array.isArray(contacts) ||
            contacts.some((contact) => typeof contact !== 'string') ||
            typeof audience !== 'string'
        ) {
            return null;
        }

        drafts.push({
            id: ++escalationStepId,
            delayMinutes: String(Number(record.delay_minutes ?? 0)),
            channels: channels as string[],
            recipient:
                typeof record.recipient === 'string' ? record.recipient : '',
            contacts: (contacts as string[]).join(', '),
            audience,
            attempts: String(Number(record.attempts ?? 1) || 1),
            retryMinutes:
                record.retry_minutes === undefined ||
                record.retry_minutes === null
                    ? ''
                    : String(Number(record.retry_minutes)),
        });
    }

    return drafts;
}

function serializeEscalationSteps(
    drafts: EscalationStepDraft[],
): Record<string, unknown>[] {
    return drafts.map((draft) => {
        const out: Record<string, unknown> = {
            delay_minutes: Number(draft.delayMinutes) || 0,
            channels: draft.channels,
            contacts: draft.contacts
                .split(',')
                .map((contact) => contact.trim())
                .filter((contact) => contact !== ''),
            attempts: Math.max(1, Number(draft.attempts) || 1),
        };

        if (draft.retryMinutes !== '') {
            out.retry_minutes = Math.max(1, Number(draft.retryMinutes) || 1);
        }

        if (draft.recipient !== '') {
            out.recipient = draft.recipient;
        }

        if (draft.audience !== '') {
            out.audience = draft.audience;
        }

        return out;
    });
}

function EscalationStepsEditor({
    steps,
    channelTypes,
    disabled,
    onChange,
}: {
    steps: EscalationStepDraft[];
    channelTypes: Option[];
    disabled: boolean;
    onChange: (steps: EscalationStepDraft[]) => void;
}) {
    const replace = (index: number, step: EscalationStepDraft) => {
        const next = [...steps];
        next[index] = step;
        onChange(next);
    };

    return (
        <ol className="flex flex-col gap-3">
            {steps.length === 0 ? (
                <li className="rounded-md border border-dashed border-border px-3 py-4 text-center text-xs text-fg-3">
                    Sin pasos: nadie más recibe aviso si el incidente no se
                    atiende a tiempo.
                </li>
            ) : null}
            {steps.map((step, index) => {
                const hasContacts = step.contacts.trim() !== '';
                const attempts = Math.max(1, Number(step.attempts) || 1);

                return (
                    <li
                        key={step.id}
                        className="relative flex gap-3 rounded-md border border-border bg-surface-2 p-3"
                    >
                        <span className="grid size-6 shrink-0 place-items-center rounded-full bg-primary/15 text-2xs font-semibold text-primary tabular-nums">
                            {index + 1}
                        </span>
                        <div className="grid min-w-0 flex-1 grid-cols-1 gap-3 sm:grid-cols-2">
                            <label
                                htmlFor={`esc-step-${step.id}-delay`}
                                className="flex flex-col gap-1 text-xs text-fg-2"
                            >
                                Minutos después de vencer el tiempo de respuesta
                                <span className="flex items-center gap-2">
                                    <Input
                                        id={`esc-step-${step.id}-delay`}
                                        type="number"
                                        min="0"
                                        value={step.delayMinutes}
                                        onChange={(e) =>
                                            replace(index, {
                                                ...step,
                                                delayMinutes: e.target.value,
                                            })
                                        }
                                        disabled={disabled}
                                        className="h-8 w-20 tabular-nums"
                                    />
                                    <span className="text-fg-3">minutos</span>
                                </span>
                            </label>
                            <label
                                htmlFor={`esc-step-${step.id}-contacts`}
                                className="flex flex-col gap-1 text-xs text-fg-2"
                            >
                                Avisar a (correos o teléfonos)
                                <Input
                                    id={`esc-step-${step.id}-contacts`}
                                    placeholder="jefe@empresa.com, +52 55 0000 0000"
                                    value={step.contacts}
                                    onChange={(e) =>
                                        replace(index, {
                                            ...step,
                                            contacts: e.target.value,
                                        })
                                    }
                                    disabled={disabled}
                                    className="h-8"
                                />
                                <span className="text-2xs text-fg-3">
                                    {hasContacts
                                        ? 'Separa varios con comas.'
                                        : 'Vacío: se avisa a las personas del equipo que elijas abajo.'}
                                </span>
                            </label>
                            {!hasContacts ? (
                                <div className="flex flex-col gap-1 text-xs text-fg-2 sm:col-span-2">
                                    A quién del equipo
                                    <div className="flex flex-wrap gap-1.5">
                                        {AUDIENCE_OPTIONS.map((option) => {
                                            const effective =
                                                step.audience !== ''
                                                    ? step.audience
                                                    : defaultAudienceFor(index);

                                            return (
                                                <ChipToggle
                                                    key={option.value}
                                                    active={
                                                        effective ===
                                                        option.value
                                                    }
                                                    disabled={disabled}
                                                    onToggle={() =>
                                                        replace(index, {
                                                            ...step,
                                                            audience:
                                                                option.value ===
                                                                defaultAudienceFor(
                                                                    index,
                                                                )
                                                                    ? ''
                                                                    : option.value,
                                                        })
                                                    }
                                                >
                                                    {option.label}
                                                </ChipToggle>
                                            );
                                        })}
                                    </div>
                                    <span className="text-2xs text-fg-3">
                                        Sin nadie en turno, el aviso va a los
                                        supervisores.
                                    </span>
                                </div>
                            ) : null}
                            <div className="flex flex-col gap-1 text-xs text-fg-2 sm:col-span-2">
                                Por
                                <div className="flex flex-wrap gap-1.5">
                                    {channelTypes.map((channel) => {
                                        const active = step.channels.includes(
                                            channel.value,
                                        );

                                        return (
                                            <ChipToggle
                                                key={channel.value}
                                                active={active}
                                                disabled={disabled}
                                                onToggle={() =>
                                                    replace(index, {
                                                        ...step,
                                                        channels: active
                                                            ? step.channels.filter(
                                                                  (c) =>
                                                                      c !==
                                                                      channel.value,
                                                              )
                                                            : [
                                                                  ...step.channels,
                                                                  channel.value,
                                                              ],
                                                    })
                                                }
                                            >
                                                {channel.label}
                                            </ChipToggle>
                                        );
                                    })}
                                </div>
                                {!hasContacts ? (
                                    <span className="text-2xs text-fg-3">
                                        Sin contactos, las vías se usan en
                                        incidentes altos y críticos; en los
                                        demás, app y correo.
                                    </span>
                                ) : null}
                            </div>
                            <label
                                htmlFor={`esc-step-${step.id}-attempts`}
                                className="flex flex-col gap-1 text-xs text-fg-2"
                            >
                                Intentos antes de pasar al siguiente paso
                                <Input
                                    id={`esc-step-${step.id}-attempts`}
                                    type="number"
                                    min="1"
                                    value={step.attempts}
                                    onChange={(e) =>
                                        replace(index, {
                                            ...step,
                                            attempts: e.target.value,
                                        })
                                    }
                                    disabled={disabled}
                                    className="h-8 w-20 tabular-nums"
                                />
                            </label>
                            {attempts > 1 ? (
                                <label
                                    htmlFor={`esc-step-${step.id}-retry`}
                                    className="flex flex-col gap-1 text-xs text-fg-2"
                                >
                                    Reintentar cada
                                    <span className="flex items-center gap-2">
                                        <Input
                                            id={`esc-step-${step.id}-retry`}
                                            type="number"
                                            min="1"
                                            placeholder={String(
                                                DEFAULT_RETRY_MINUTES,
                                            )}
                                            value={step.retryMinutes}
                                            onChange={(e) =>
                                                replace(index, {
                                                    ...step,
                                                    retryMinutes:
                                                        e.target.value,
                                                })
                                            }
                                            disabled={disabled}
                                            className="h-8 w-20 tabular-nums"
                                        />
                                        <span className="text-fg-3">
                                            minutos
                                        </span>
                                    </span>
                                </label>
                            ) : null}
                        </div>
                        {!disabled ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-8 shrink-0 text-fg-3 hover:text-severity-critical"
                                aria-label={`Quitar paso ${index + 1}`}
                                onClick={() =>
                                    onChange(
                                        steps.filter((_, i) => i !== index),
                                    )
                                }
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        ) : null}
                    </li>
                );
            })}
            {!disabled ? (
                <li>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            onChange([
                                ...steps,
                                {
                                    id: ++escalationStepId,
                                    delayMinutes: String(
                                        (Number(
                                            steps[steps.length - 1]
                                                ?.delayMinutes,
                                        ) || 0) + 10,
                                    ),
                                    channels: [],
                                    recipient: '',
                                    contacts: '',
                                    audience: '',
                                    attempts: '1',
                                    retryMinutes: '',
                                },
                            ])
                        }
                    >
                        <Plus className="size-3.5" /> Añadir paso
                    </Button>
                </li>
            ) : null}
        </ol>
    );
}

function EscalationCard({
    config,
    conditionFields,
    channelTypes,
    canManage,
    saving,
    onSave,
}: {
    config: EscalationConfigRow;
    conditionFields: ConditionFieldDef[];
    channelTypes: Option[];
    canManage: boolean;
    saving: boolean;
    onSave: (
        config: EscalationConfigRow,
        steps: unknown[],
        conditions: Record<string, unknown>,
    ) => Promise<void>;
}) {
    const [stepDrafts, setStepDrafts] = useState<EscalationStepDraft[] | null>(
        () => parseEscalationSteps(config.steps),
    );
    const [raw, setRaw] = useState(JSON.stringify(config.steps, null, 2));
    // PHP serializa un mapa vacío como `[]`: se trata como "sin condiciones"
    // para no mandar al editor visual a modo avanzado sin motivo.
    const [conditions, setConditions] = useState<Record<string, unknown>>(
        Array.isArray(config.triggerConditions)
            ? {}
            : (config.triggerConditions ?? {}),
    );
    const title = escalationTitle(config.escalationType);

    const save = () => {
        if (stepDrafts !== null) {
            void onSave(
                config,
                serializeEscalationSteps(stepDrafts),
                conditions,
            );

            return;
        }

        const parsed = parseJson(raw, `los pasos de «${title}»`);

        if (parsed === null || !Array.isArray(parsed)) {
            if (parsed !== null) {
                toast.error('Los pasos deben ser una lista.');
            }

            return;
        }

        void onSave(config, parsed, conditions);
    };

    return (
        <SettingsSection
            title={title}
            description="Si el incidente vence su tiempo de respuesta sin atenderse, SAM sigue estos pasos en orden."
            actions={<StatePill on={config.isActive} />}
        >
            <FormCard className="gap-5">
                <div className="flex flex-col gap-2">
                    <div>
                        <h3 className="text-sm font-semibold text-fg-1">
                            Se activa cuando
                        </h3>
                        <p className="text-2xs text-fg-3">
                            Condiciones que debe cumplir el incidente para
                            seguir estos pasos.
                        </p>
                    </div>
                    <ConditionBuilder
                        variant="flat-equality"
                        fields={conditionFields}
                        allowUnknownFields
                        value={conditions}
                        onChange={setConditions}
                        disabled={!canManage}
                    />
                </div>
                <div className="flex flex-col gap-2">
                    <div>
                        <h3 className="text-sm font-semibold text-fg-1">
                            Pasos
                        </h3>
                        <p className="text-2xs text-fg-3">
                            Cada paso sólo se dispara si el incidente sigue sin
                            atenderse.
                        </p>
                    </div>
                    {stepDrafts !== null ? (
                        <EscalationStepsEditor
                            steps={stepDrafts}
                            channelTypes={channelTypes}
                            disabled={!canManage}
                            onChange={setStepDrafts}
                        />
                    ) : (
                        <>
                            <p className="text-2xs text-fg-3">
                                Estos pasos usan opciones que el editor visual
                                no muestra. Puedes editarlos en formato de
                                texto; no se pierde ningún dato.
                            </p>
                            <JsonField
                                id={`esc-${config.id}-raw`}
                                value={raw}
                                onChange={setRaw}
                                disabled={!canManage}
                            />
                        </>
                    )}
                </div>
                {canManage ? (
                    <div className="-mx-5 -mb-5 flex justify-end rounded-b-lg border-t border-border bg-surface-2 px-5 py-3">
                        <Button size="sm" onClick={save} disabled={saving}>
                            Guardar escalamiento
                        </Button>
                    </div>
                ) : null}
            </FormCard>
        </SettingsSection>
    );
}
