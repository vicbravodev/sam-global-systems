import { Plus, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { ChipToggle } from '@/components/sam/settings/controls';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { channelLabel } from '@/lib/labels';
import type { HosChannelOption } from '@/types/hos';
import {
    addNoticeStep,
    HOS_MAX_LADDER_STEPS,
    removeStep,
    setEscalation,
} from './config-lib';
import type { HosDraftErrors, HosLadderDraft } from './config-lib';

export interface HosLadderEditorProps {
    ladder: HosLadderDraft[];
    channels: HosChannelOption[];
    errors: HosDraftErrors;
    disabled: boolean;
    onChange: (ladder: HosLadderDraft[]) => void;
}

/** Escalones de insistencia al chofer y, al final, el incidente al equipo. */
export function HosLadderEditor({
    ladder,
    channels,
    errors,
    disabled,
    onChange,
}: HosLadderEditorProps) {
    const escalates = ladder.some((step) => step.escalate);
    const notices = ladder.filter((step) => !step.escalate).length;
    const defaultChannels = channels
        .filter((channel) => channel.available)
        .slice(0, 1)
        .map((channel) => channel.value);

    return (
        <div className="flex flex-col gap-3">
            <ol className="flex flex-col gap-3">
                {ladder.map((step, index) => (
                    <LadderStepRow
                        key={step.id}
                        step={step}
                        index={index}
                        channels={channels}
                        errors={errors}
                        disabled={disabled}
                        canRemove={!step.escalate && notices > 1}
                        onChange={(next) =>
                            onChange(
                                ladder.map((current, i) =>
                                    i === index ? next : current,
                                ),
                            )
                        }
                        onRemove={() => onChange(removeStep(ladder, index))}
                    />
                ))}
            </ol>
            <InputError message={errors.ladder} />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={disabled || ladder.length >= HOS_MAX_LADDER_STEPS}
                    onClick={() =>
                        onChange(addNoticeStep(ladder, defaultChannels))
                    }
                >
                    <Plus className="size-3.5" />
                    Agregar escalón
                </Button>
                <label
                    htmlFor="hos-ladder-escalate"
                    className="flex items-center gap-2.5 text-sm text-fg-2"
                >
                    <Switch
                        id="hos-ladder-escalate"
                        checked={escalates}
                        disabled={disabled}
                        onCheckedChange={(on) =>
                            onChange(setEscalation(ladder, on))
                        }
                    />
                    Si no corrige, abrir un incidente para tu equipo
                </label>
            </div>
        </div>
    );
}

function LadderStepRow({
    step,
    index,
    channels,
    errors,
    disabled,
    canRemove,
    onChange,
    onRemove,
}: {
    step: HosLadderDraft;
    index: number;
    channels: HosChannelOption[];
    errors: HosDraftErrors;
    disabled: boolean;
    canRemove: boolean;
    onChange: (step: HosLadderDraft) => void;
    onRemove: () => void;
}) {
    const key = `ladder.${index}`;
    const inputId = `hos-ladder-${step.id}-after`;
    const toggle = (value: string) =>
        onChange({
            ...step,
            channels: step.channels.includes(value)
                ? step.channels.filter((channel) => channel !== value)
                : [...step.channels, value],
        });

    return (
        <li className="flex gap-3 rounded-md border border-border bg-surface-2 p-3">
            <span className="grid size-6 shrink-0 place-items-center rounded-full bg-primary/15 text-2xs font-semibold text-primary tabular-nums">
                {index + 1}
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                <label
                    htmlFor={inputId}
                    className="flex flex-wrap items-center gap-2 text-xs text-fg-2"
                >
                    {index === 0
                        ? 'Al llegar al límite'
                        : 'Minutos después del límite'}
                    <Input
                        id={inputId}
                        type="number"
                        min="0"
                        value={step.afterMinutes}
                        disabled={disabled || index === 0}
                        aria-invalid={
                            errors[`${key}.after_minutes`] !== undefined
                        }
                        onChange={(event) =>
                            onChange({
                                ...step,
                                afterMinutes: event.target.value,
                            })
                        }
                        className="h-8 w-20 tabular-nums"
                    />
                    <span className="text-fg-3">min</span>
                </label>
                <InputError message={errors[`${key}.after_minutes`]} />
                {step.escalate ? (
                    <p className="text-xs text-fg-2">
                        Abre un incidente de HOS para tu equipo de monitoreo
                        (sigue su escalamiento de siempre).
                    </p>
                ) : (
                    <div
                        className="flex flex-wrap gap-1.5"
                        role="group"
                        aria-label={`Canales del escalón ${index + 1}`}
                    >
                        {channels.map((channel) => {
                            const active = step.channels.includes(
                                channel.value,
                            );

                            return (
                                <ChipToggle
                                    key={channel.value}
                                    active={active}
                                    disabled={
                                        disabled ||
                                        (!channel.available && !active)
                                    }
                                    label={
                                        channel.available
                                            ? channelLabel(channel.value)
                                            : `${channelLabel(channel.value)} (no disponible)`
                                    }
                                    onToggle={() => toggle(channel.value)}
                                >
                                    {channelLabel(channel.value)}
                                </ChipToggle>
                            );
                        })}
                    </div>
                )}
                <InputError
                    message={
                        errors[`${key}.channels`] ?? errors[`${key}.escalate`]
                    }
                />
            </div>
            {canRemove ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label={`Quitar escalón ${index + 1}`}
                    disabled={disabled}
                    onClick={onRemove}
                >
                    <Trash2 className="size-3.5" />
                </Button>
            ) : null}
        </li>
    );
}
