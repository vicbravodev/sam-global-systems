import {
    ChevronRight,
    History,
    MoreHorizontal,
    Pencil,
    Play,
    Trash2,
    Zap,
} from 'lucide-react';
import type { ConditionFieldDef } from '@/components/sam/condition-builder';
import { MetaChip } from '@/components/sam/meta-chip';
import { RelativeTime } from '@/components/sam/relative-time';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Switch } from '@/components/ui/switch';
import { formatDateTime, formatNumber } from '@/lib/format';
import { minutesSince } from '@/lib/time';
import { cn } from '@/lib/utils';
import { conditionPhrases } from './api';
import {
    ACTIONS,
    executionStatus,
    isRunning,
    stepDelay,
    stepPhrase,
    stepRecipient,
    TRIGGERS,
    triggerSentence,
} from './copy';
import type { AutomationOptions, WorkflowRow, WorkflowRunStat } from './types';

interface WorkflowRowProps {
    workflow: WorkflowRow;
    stat: WorkflowRunStat | undefined;
    options: AutomationOptions;
    conditionFields: ConditionFieldDef[];
    canManage: boolean;
    toggling: boolean;
    onToggle: (workflow: WorkflowRow, next: boolean) => void;
    onEdit: (workflow: WorkflowRow) => void;
    onRunNow: (workflow: WorkflowRow) => void;
    onShowExecutions: (workflow: WorkflowRow) => void;
    onDelete: (workflow: WorkflowRow) => void;
}

/**
 * Una automatización leída como frase: "Cuando se crea un incidente
 * (Prioridad: Alta) → 1 WhatsApp a Monitorista → 2 …", con su interruptor,
 * la última ejecución y el menú de acciones.
 */
export function WorkflowListRow({
    workflow,
    stat,
    options,
    conditionFields,
    canManage,
    toggling,
    onToggle,
    onEdit,
    onRunNow,
    onShowExecutions,
    onDelete,
}: WorkflowRowProps) {
    const running = isRunning(workflow);
    const TriggerIcon = TRIGGERS[workflow.triggerType ?? '']?.icon ?? Zap;
    const conditions = conditionPhrases(
        workflow.triggerConditions,
        conditionFields,
    );
    const isManual = workflow.triggerType === 'manual_trigger';
    const switchId = `wf-switch-${workflow.id}`;

    return (
        <li
            className={cn(
                'flex gap-3 px-5 py-3.5 transition-colors hover:bg-surface-2/50',
                !running && 'bg-surface-1/40',
            )}
        >
            <div className="pt-0.5">
                <Switch
                    id={switchId}
                    checked={running}
                    disabled={!canManage || toggling}
                    onCheckedChange={(next) => onToggle(workflow, next)}
                    aria-label={
                        running
                            ? `Apagar «${workflow.name}»`
                            : `Encender «${workflow.name}»`
                    }
                />
            </div>

            <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                    <span
                        className={cn(
                            'min-w-0 truncate text-sm font-medium',
                            running ? 'text-fg-1' : 'text-fg-2',
                        )}
                        title={workflow.description ?? undefined}
                    >
                        {workflow.name}
                    </span>
                    {running ? (
                        <span className="text-2xs font-medium text-severity-low">
                            Encendida
                        </span>
                    ) : workflow.status === 'draft' ? (
                        <MetaChip>Borrador</MetaChip>
                    ) : (
                        <span className="text-2xs text-fg-3">Apagada</span>
                    )}
                </div>

                <p className="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs text-fg-2">
                    <TriggerIcon
                        className="size-3.5 shrink-0 text-fg-3"
                        aria-hidden="true"
                    />
                    <span>
                        {triggerSentence(
                            workflow.triggerType,
                            options.triggerTypes,
                        )}
                    </span>
                    {conditions.length > 0 && (
                        <span className="text-fg-3">
                            · sólo si {conditions.join(' y ')}
                        </span>
                    )}
                </p>

                <ol className="flex flex-wrap items-center gap-x-1 gap-y-1.5">
                    {workflow.steps.map((step, index) => {
                        const copy = ACTIONS[step.action_type ?? ''];
                        const Icon = copy?.icon ?? Zap;
                        const delay = stepDelay(step);

                        return (
                            <li key={index} className="flex items-center gap-1">
                                {index > 0 && (
                                    <ChevronRight
                                        className="size-3 text-fg-3"
                                        aria-hidden="true"
                                    />
                                )}
                                <span className="inline-flex items-center gap-1.5 rounded-md border border-border bg-surface-2 px-2 py-0.5 text-xs text-fg-1">
                                    <span className="text-2xs text-fg-3 tabular-nums">
                                        {index + 1}
                                    </span>
                                    <Icon
                                        className="size-3.5 shrink-0 text-fg-2"
                                        aria-hidden="true"
                                    />
                                    {stepPhrase(
                                        step.action_type,
                                        stepRecipient(workflow, index),
                                        options.actionTypes,
                                    )}
                                    {delay && (
                                        <span className="text-2xs text-fg-3">
                                            · {delay}
                                        </span>
                                    )}
                                    {step.execution_mode ===
                                        'requires_confirmation' && (
                                        <span className="text-2xs text-severity-high">
                                            · con confirmación
                                        </span>
                                    )}
                                </span>
                            </li>
                        );
                    })}
                    {workflow.steps.length === 0 && (
                        <li className="text-xs text-fg-3">
                            Sin pasos configurados.
                        </li>
                    )}
                </ol>

                <LastRun stat={stat} className="flex sm:hidden" />
            </div>

            <div className="flex shrink-0 items-start gap-2">
                <LastRun stat={stat} className="hidden w-44 sm:flex" />

                {canManage && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                size="icon"
                                variant="ghost"
                                className="size-8"
                                aria-label={`Acciones de «${workflow.name}»`}
                            >
                                <MoreHorizontal size={15} />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                            <DropdownMenuItem onSelect={() => onEdit(workflow)}>
                                <Pencil size={13} /> Editar
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                disabled={!isManual}
                                onSelect={() => onRunNow(workflow)}
                                title={
                                    isManual
                                        ? undefined
                                        : 'Sólo las automatizaciones que se ejecutan a mano'
                                }
                            >
                                <Play size={13} />
                                <span className="flex flex-col">
                                    Ejecutar ahora
                                    {!isManual && (
                                        <span className="text-2xs text-fg-3">
                                            Ésta se activa sola
                                        </span>
                                    )}
                                </span>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={() => onShowExecutions(workflow)}
                            >
                                <History size={13} /> Ver ejecuciones
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => onDelete(workflow)}
                            >
                                <Trash2 size={13} /> Eliminar
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>
        </li>
    );
}

function LastRun({
    stat,
    className,
}: {
    stat: WorkflowRunStat | undefined;
    className?: string;
}) {
    if (!stat || stat.lastRunAt === null) {
        return (
            <div className={cn('flex-col text-2xs text-fg-3', className)}>
                Nunca se ha ejecutado
            </div>
        );
    }

    const status = executionStatus(stat.lastStatus);

    return (
        <div
            className={cn(
                'flex-col gap-0.5 text-2xs text-fg-3 sm:items-end sm:text-right',
                className,
            )}
        >
            <span
                className="flex items-center gap-1.5"
                title={formatDateTime(stat.lastRunAt)}
            >
                <span
                    className={cn('size-1.5 rounded-full', status.dot)}
                    aria-hidden="true"
                />
                <span className={status.text}>{status.label}</span>
                <RelativeTime minutes={minutesSince(stat.lastRunAt)} />
            </span>
            <span>
                {formatNumber(stat.runs30d)}{' '}
                {stat.runs30d === 1 ? 'acción' : 'acciones'} en 30 días
                {stat.failed30d > 0 && (
                    <span className="text-severity-critical">
                        {' · '}
                        {formatNumber(stat.failed30d)}{' '}
                        {stat.failed30d === 1 ? 'falló' : 'fallaron'}
                    </span>
                )}
            </span>
        </div>
    );
}
