import { Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { ConditionRow } from './condition-row';
import { makeGroup, makeLeaf } from './lib';
import type { ConditionFieldDef, VisualGroup, VisualNode } from './types';

/**
 * Grupo recursivo "Se cumplen todas / cualquiera". Los subgrupos van con
 * sangría y borde izquierdo de 1px (no es acento, es estructura).
 */

interface ConditionGroupProps {
    group: VisualGroup;
    fields: ConditionFieldDef[];
    allowUnknownFields?: boolean;
    disabled?: boolean;
    depth?: number;
    onChange: (group: VisualGroup) => void;
    onRemove?: () => void;
}

const MAX_DEPTH = 4;

export function ConditionGroup({
    group,
    fields,
    allowUnknownFields,
    disabled,
    depth = 0,
    onChange,
    onRemove,
}: ConditionGroupProps) {
    const replaceChild = (index: number, node: VisualNode) => {
        const children = [...group.children];
        children[index] = node;
        onChange({ ...group, children });
    };

    const removeChild = (index: number) => {
        onChange({
            ...group,
            children: group.children.filter((_, i) => i !== index),
        });
    };

    return (
        <div
            className={cn(
                'flex flex-col gap-2',
                depth > 0 && 'ml-3 border-l border-border pl-3',
            )}
        >
            <div className="flex items-center gap-2">
                <Select
                    value={group.logic}
                    onValueChange={(logic) =>
                        onChange({ ...group, logic: logic as 'all' | 'any' })
                    }
                    disabled={disabled}
                >
                    <SelectTrigger className="h-7 w-56 text-xs">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">
                            Se cumplen todas las condiciones
                        </SelectItem>
                        <SelectItem value="any">
                            Se cumple cualquiera de las condiciones
                        </SelectItem>
                    </SelectContent>
                </Select>
                {onRemove && !disabled && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-7 text-xs text-fg-3"
                        onClick={onRemove}
                    >
                        Quitar grupo
                    </Button>
                )}
            </div>

            {group.children.map((child, index) =>
                child.kind === 'group' ? (
                    <ConditionGroup
                        key={child.id}
                        group={child}
                        fields={fields}
                        allowUnknownFields={allowUnknownFields}
                        disabled={disabled}
                        depth={depth + 1}
                        onChange={(next) => replaceChild(index, next)}
                        onRemove={() => removeChild(index)}
                    />
                ) : (
                    <ConditionRow
                        key={child.id}
                        leaf={child}
                        fields={fields}
                        allowUnknownFields={allowUnknownFields}
                        disabled={disabled}
                        onChange={(next) => replaceChild(index, next)}
                        onRemove={() => removeChild(index)}
                    />
                ),
            )}

            {!disabled && (
                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-7 text-xs text-fg-2"
                        onClick={() =>
                            onChange({
                                ...group,
                                children: [...group.children, makeLeaf()],
                            })
                        }
                    >
                        <Plus className="size-3.5" />
                        Condición
                    </Button>
                    {depth < MAX_DEPTH && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-7 text-xs text-fg-3"
                            onClick={() =>
                                onChange({
                                    ...group,
                                    children: [
                                        ...group.children,
                                        makeGroup(
                                            group.logic === 'all'
                                                ? 'any'
                                                : 'all',
                                        ),
                                    ],
                                })
                            }
                        >
                            <Plus className="size-3.5" />
                            Grupo
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}
