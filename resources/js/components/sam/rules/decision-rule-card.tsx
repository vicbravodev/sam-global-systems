import {
    FlaskConical,
    MoreHorizontal,
    Pencil,
    ShieldCheck,
    Trash2,
    Eye,
} from 'lucide-react';
import type { ConditionFieldDef } from '@/components/sam/condition-builder';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Switch } from '@/components/ui/switch';
import { TONE_DOT, TONE_TEXT } from '@/lib/tone';
import { cn } from '@/lib/utils';
import { ordinal } from './lib';
import { RuleSentence } from './rule-sentence';
import type { DecisionRuleRow } from './types';

interface DecisionRuleCardProps {
    rule: DecisionRuleRow;
    fields: ConditionFieldDef[];
    /** Sólo reglas propias con permiso: las de SAM son de solo lectura. */
    editable: boolean;
    /** Muestra la etiqueta "De SAM" sólo si conviven reglas de SAM y propias. */
    showOrigin: boolean;
    onOpen: () => void;
    onTest: () => void;
    onToggle: (active: boolean) => void;
    onDelete: () => void;
    toggling: boolean;
}

function OrderBadge({ rule }: { rule: DecisionRuleRow }) {
    if (rule.evaluationOrder !== null) {
        return (
            <div className="flex w-10 shrink-0 flex-col items-center gap-0.5 pt-0.5 sm:w-14">
                <span className="grid size-8 place-items-center rounded-full border border-border bg-surface-2 text-xs font-semibold text-fg-1 tabular-nums">
                    {ordinal(rule.evaluationOrder)}
                </span>
                <span className="text-3xs text-fg-3">se revisa</span>
            </div>
        );
    }

    return (
        <div className="flex w-10 shrink-0 flex-col items-center gap-0.5 pt-0.5 sm:w-14">
            <span className="grid size-8 place-items-center rounded-full border border-dashed border-border text-xs text-fg-3">
                —
            </span>
            <span className="text-center text-3xs leading-tight text-fg-3">
                {rule.inEffectiveRuleset ? 'apagada' : 'no se usa'}
            </span>
        </div>
    );
}

/**
 * Una regla de decisión como frase legible: nombre, "Si … → resultado",
 * detalles secundarios y, a la derecha, el switch y el menú de acciones.
 */
export function DecisionRuleCard({
    rule,
    fields,
    editable,
    showOrigin,
    onOpen,
    onTest,
    onToggle,
    onDelete,
    toggling,
}: DecisionRuleCardProps) {
    return (
        <li
            className={cn(
                'group flex items-start gap-2.5 px-3 py-3.5 transition-colors hover:bg-surface-1 sm:gap-3 sm:px-5',
                !rule.isActive && 'bg-surface-1/50',
            )}
        >
            <OrderBadge rule={rule} />

            <div
                className={cn(
                    'flex min-w-0 flex-1 flex-col gap-1.5',
                    !rule.isActive && 'opacity-70',
                )}
            >
                <button
                    type="button"
                    onClick={onOpen}
                    className="w-fit text-left text-sm font-semibold text-fg-1 hover:underline focus-visible:underline focus-visible:outline-none"
                >
                    {rule.name}
                </button>
                <RuleSentence
                    conditions={rule.conditions}
                    fields={fields}
                    outcomeCode={rule.outcomeCode}
                />
                {(rule.description ||
                    rule.stopProcessing ||
                    (showOrigin && rule.isGlobal) ||
                    !rule.inEffectiveRuleset) && (
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-2xs text-fg-3">
                        {rule.stopProcessing && (
                            <span className="inline-flex items-center gap-1">
                                <ShieldCheck
                                    className="size-3"
                                    aria-hidden="true"
                                />
                                Si se cumple, no se revisan las siguientes
                            </span>
                        )}
                        {showOrigin && rule.isGlobal && (
                            <span className="rounded-sm border border-border px-1 py-px">
                                Regla de SAM
                            </span>
                        )}
                        {!rule.inEffectiveRuleset && (
                            <span>
                                Pertenece a otro conjunto de reglas que tu
                                cuenta no usa.
                            </span>
                        )}
                        {rule.description && (
                            <span className="line-clamp-2 basis-full text-fg-3">
                                {rule.description}
                            </span>
                        )}
                    </div>
                )}
            </div>

            <div className="flex shrink-0 items-center gap-1">
                {editable ? (
                    <Switch
                        checked={rule.isActive}
                        disabled={toggling}
                        onCheckedChange={onToggle}
                        aria-label={
                            rule.isActive
                                ? `Apagar «${rule.name}»`
                                : `Encender «${rule.name}»`
                        }
                    />
                ) : (
                    <span
                        className={cn(
                            'inline-flex items-center gap-1.5 text-xs',
                            TONE_TEXT[rule.isActive ? 'ok' : 'neutral'],
                        )}
                    >
                        <span
                            className={cn(
                                'size-1.5 rounded-full',
                                TONE_DOT[rule.isActive ? 'ok' : 'neutral'],
                            )}
                            aria-hidden="true"
                        />
                        {rule.isActive ? 'Encendida' : 'Apagada'}
                    </span>
                )}
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            aria-label={`Acciones de «${rule.name}»`}
                        >
                            <MoreHorizontal className="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onSelect={onOpen}>
                            {editable ? (
                                <Pencil className="size-3.5" />
                            ) : (
                                <Eye className="size-3.5" />
                            )}
                            {editable ? 'Editar' : 'Ver detalle'}
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={onTest}>
                            <FlaskConical className="size-3.5" />
                            Probar
                        </DropdownMenuItem>
                        {editable && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={onDelete}
                                >
                                    <Trash2 className="size-3.5" />
                                    Eliminar
                                </DropdownMenuItem>
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </li>
    );
}
