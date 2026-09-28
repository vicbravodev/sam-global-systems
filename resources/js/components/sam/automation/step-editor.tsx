import { Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import {
    ACTION_ORDER,
    ACTIONS,
    actionTitle,
    DELAY_CHOICES,
    TARGET_KINDS,
} from './copy';
import type { TargetKind } from './copy';
import type { Option, TeamTargets, WorkflowStep } from './types';

/** Borrador editable de un paso; `original` conserva claves que no se editan. */
export interface StepDraft {
    key: string;
    action_type: string;
    target_type: string;
    target_reference: string;
    /** Nombre legible del destino actual (para roles/usuarios fuera de catálogo). */
    target_name: string | null;
    delay_seconds: string;
    confirm: boolean;
    original: WorkflowStep | null;
}

let draftSeq = 0;

export function newStepDraft(): StepDraft {
    draftSeq += 1;

    return {
        key: `new-${draftSeq}`,
        action_type: 'send_whatsapp',
        target_type: 'role',
        target_reference: '',
        target_name: null,
        delay_seconds: '0',
        confirm: false,
        original: null,
    };
}

export function stepDraftFrom(
    step: WorkflowStep,
    recipient: string | null,
    index: number,
): StepDraft {
    return {
        key: `step-${index}`,
        action_type: String(step.action_type ?? 'send_email'),
        target_type: String(step.target_type ?? ''),
        target_reference: String(step.target_reference ?? ''),
        target_name: recipient,
        delay_seconds: String(Number(step.delay_seconds ?? 0)),
        confirm: step.execution_mode === 'requires_confirmation',
        original: step,
    };
}

/** Paso listo para `steps_json`, sin perder plantilla/payload originales. */
export function stepPayload(draft: StepDraft, index: number): WorkflowStep {
    const original = draft.original ?? {};
    const copy = ACTIONS[draft.action_type];
    const needsTarget = (copy?.targets.length ?? 1) > 0;
    const sameAction = original.action_type === draft.action_type;

    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    const { template_code, ...rest } = original;

    const originalMode =
        typeof original.execution_mode === 'string' &&
        original.execution_mode !== 'requires_confirmation'
            ? original.execution_mode
            : 'async';

    return {
        // La plantilla de mensaje sólo sigue valiendo si la acción no cambió.
        ...(sameAction ? original : rest),
        order: index + 1,
        action_type: draft.action_type,
        execution_mode: draft.confirm ? 'requires_confirmation' : originalMode,
        target_type: needsTarget ? draft.target_type : undefined,
        target_reference: needsTarget ? draft.target_reference : undefined,
        delay_seconds: Number(draft.delay_seconds) || 0,
    };
}

export function stepProblem(draft: StepDraft): string | null {
    const copy = ACTIONS[draft.action_type];

    if (
        copy &&
        copy.targets.length > 0 &&
        draft.target_reference.trim() === ''
    ) {
        return 'Elige a quién va dirigido este paso.';
    }

    return null;
}

interface StepEditorProps {
    draft: StepDraft;
    index: number;
    total: number;
    actionOptions: Option[];
    teamTargets: TeamTargets;
    error?: string;
    onChange: (next: StepDraft) => void;
    onRemove: () => void;
}

export function StepEditor({
    draft,
    index,
    total,
    actionOptions,
    teamTargets,
    error,
    onChange,
    onRemove,
}: StepEditorProps) {
    const copy = ACTIONS[draft.action_type];
    const kinds = copy?.targets ?? [];
    const kind = (
        kinds.includes(draft.target_type as TargetKind)
            ? draft.target_type
            : kinds[0]
    ) as TargetKind | undefined;
    const idPrefix = `step-${draft.key}`;

    const known = new Set(actionOptions.map((o) => o.value));
    const actions = ACTION_ORDER.filter(
        (value) => known.has(value) || value === draft.action_type,
    );

    const setAction = (value: string) => {
        const nextKinds = ACTIONS[value]?.targets ?? [];
        const keepTarget = nextKinds.includes(draft.target_type as TargetKind);

        onChange({
            ...draft,
            action_type: value,
            target_type: keepTarget ? draft.target_type : (nextKinds[0] ?? ''),
            target_reference: keepTarget ? draft.target_reference : '',
            target_name: keepTarget ? draft.target_name : null,
        });
    };

    const setKind = (value: TargetKind) =>
        onChange({
            ...draft,
            target_type: value,
            target_reference: '',
            target_name: null,
        });

    const withCurrent = (list: Option[]): Option[] =>
        draft.target_reference !== '' &&
        !list.some((o) => o.value === draft.target_reference)
            ? [
                  ...list,
                  {
                      value: draft.target_reference,
                      label: draft.target_name ?? draft.target_reference,
                  },
              ]
            : list;

    const delayChoices = DELAY_CHOICES.some(
        (c) => c.value === draft.delay_seconds,
    )
        ? DELAY_CHOICES
        : [
              ...DELAY_CHOICES,
              {
                  value: draft.delay_seconds,
                  label: `Esperar ${draft.delay_seconds} s`,
              },
          ];

    return (
        <li className="flex flex-col gap-3 rounded-lg border border-border bg-surface-1 p-3.5">
            <div className="flex items-start gap-2.5">
                <span className="mt-1.5 grid size-5 shrink-0 place-items-center rounded-full bg-primary/10 text-2xs font-semibold text-primary tabular-nums">
                    {index + 1}
                </span>
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <Label htmlFor={`${idPrefix}-action`} className="sr-only">
                        Qué hacer en el paso {index + 1}
                    </Label>
                    <Select value={draft.action_type} onValueChange={setAction}>
                        <SelectTrigger
                            id={`${idPrefix}-action`}
                            className="h-9 w-full sm:w-72"
                        >
                            <SelectValue>
                                {actionTitle(draft.action_type, actionOptions)}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            {actions.map((value) => {
                                const item = ACTIONS[value];
                                const Icon = item?.icon;

                                return (
                                    <SelectItem
                                        key={value}
                                        value={value}
                                        disabled={
                                            item?.comingSoon &&
                                            value !== draft.action_type
                                        }
                                    >
                                        {Icon && <Icon className="size-3.5" />}
                                        {actionTitle(value, actionOptions)}
                                        {item?.comingSoon && (
                                            <span className="text-2xs text-fg-3">
                                                (próximamente)
                                            </span>
                                        )}
                                    </SelectItem>
                                );
                            })}
                        </SelectContent>
                    </Select>
                    {copy && <p className="text-2xs text-fg-3">{copy.help}</p>}
                </div>
                {total > 1 && (
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        className="size-8 shrink-0 text-fg-3 hover:text-severity-critical"
                        onClick={onRemove}
                        aria-label={`Quitar el paso ${index + 1}`}
                    >
                        <Trash2 size={14} />
                    </Button>
                )}
            </div>

            {kind && (
                <div className="flex flex-col gap-1.5 pl-7">
                    <span className="text-xs font-medium text-fg-2">
                        A quién
                    </span>
                    {kinds.length > 1 && (
                        <div
                            role="radiogroup"
                            aria-label="Tipo de destinatario"
                            className="flex flex-wrap gap-1"
                        >
                            {kinds.map((value) => (
                                <button
                                    key={value}
                                    type="button"
                                    role="radio"
                                    aria-checked={kind === value}
                                    onClick={() => setKind(value)}
                                    className={cn(
                                        'rounded-full border px-2.5 py-1 text-2xs font-medium transition-colors',
                                        kind === value
                                            ? 'border-primary/40 bg-primary/10 text-primary'
                                            : 'border-border text-fg-2 hover:text-fg-1',
                                    )}
                                >
                                    {TARGET_KINDS[value].label}
                                </button>
                            ))}
                        </div>
                    )}
                    {kind === 'role' || kind === 'user' ? (
                        <Combobox
                            aria-label="Destinatario"
                            options={withCurrent(
                                kind === 'role'
                                    ? teamTargets.roles
                                    : teamTargets.users,
                            )}
                            value={
                                draft.target_reference === ''
                                    ? null
                                    : draft.target_reference
                            }
                            onChange={(value) =>
                                onChange({
                                    ...draft,
                                    target_type: kind,
                                    target_reference: value ?? '',
                                    target_name: null,
                                })
                            }
                            placeholder={TARGET_KINDS[kind].placeholder}
                            className="w-full sm:w-72"
                        />
                    ) : (
                        <Input
                            aria-label="Destinatario"
                            type={
                                kind === 'email'
                                    ? 'email'
                                    : kind === 'phone'
                                      ? 'tel'
                                      : 'url'
                            }
                            placeholder={TARGET_KINDS[kind].placeholder}
                            value={draft.target_reference}
                            onChange={(e) =>
                                onChange({
                                    ...draft,
                                    target_type: kind,
                                    target_reference: e.target.value,
                                })
                            }
                            className="w-full sm:w-72"
                        />
                    )}
                    {kind === 'role' && (
                        <p className="text-2xs text-fg-3">
                            Le llega a todas las personas del equipo con ese
                            rol.
                        </p>
                    )}
                </div>
            )}

            <div className="flex flex-col gap-2 pl-7 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Label
                        htmlFor={`${idPrefix}-delay`}
                        className="text-xs font-medium text-fg-2"
                    >
                        Cuándo
                    </Label>
                    <Select
                        value={draft.delay_seconds}
                        onValueChange={(value) =>
                            onChange({ ...draft, delay_seconds: value })
                        }
                    >
                        <SelectTrigger
                            id={`${idPrefix}-delay`}
                            className="h-8 w-44"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {delayChoices.map((choice) => (
                                <SelectItem
                                    key={choice.value}
                                    value={choice.value}
                                >
                                    {choice.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <span className="text-2xs whitespace-nowrap text-fg-3">
                        {index === 0
                            ? 'desde que se activa'
                            : 'tras el paso anterior'}
                    </span>
                </div>
                <label className="flex items-center gap-2 text-xs text-fg-2">
                    <Checkbox
                        checked={draft.confirm}
                        onCheckedChange={(value) =>
                            onChange({ ...draft, confirm: value === true })
                        }
                    />
                    Pedir confirmación antes de hacerlo
                </label>
            </div>

            {error && <InputError message={error} className="pl-7 text-xs" />}
        </li>
    );
}
