import { Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { LIST_OPERATORS, NO_VALUE_OPERATORS, OPERATOR_LABELS } from './lib';
import type { ConditionFieldDef, VisualLeaf } from './types';
import { ValueInput } from './value-input';

/** Fila de condición: campo · operador · valor · quitar. */

interface ConditionRowProps {
    leaf: VisualLeaf;
    fields: ConditionFieldDef[];
    allowUnknownFields?: boolean;
    disabled?: boolean;
    onChange: (leaf: VisualLeaf) => void;
    onRemove: () => void;
}

export function ConditionRow({
    leaf,
    fields,
    allowUnknownFields = false,
    disabled,
    onChange,
    onRemove,
}: ConditionRowProps) {
    const fieldDef = fields.find((field) => field.key === leaf.field) ?? null;
    const operators = fieldDef?.operators ?? Object.keys(OPERATOR_LABELS);

    const changeField = (key: string | null) => {
        const next = fields.find((field) => field.key === key) ?? null;
        const operator =
            next && !next.operators.includes(leaf.operator)
                ? next.operators[0]
                : leaf.operator;

        onChange({
            ...leaf,
            field: key ?? '',
            operator,
            value: LIST_OPERATORS.includes(operator) ? [] : '',
        });
    };

    const changeOperator = (operator: string) => {
        const wasList = LIST_OPERATORS.includes(leaf.operator);
        const isList = LIST_OPERATORS.includes(operator);

        onChange({
            ...leaf,
            operator,
            value:
                wasList === isList && !NO_VALUE_OPERATORS.includes(operator)
                    ? leaf.value
                    : isList
                      ? []
                      : '',
        });
    };

    return (
        <div className="flex flex-wrap items-center gap-2">
            <Combobox
                options={fields.map((field) => ({
                    value: field.key,
                    label: field.label,
                }))}
                value={leaf.field === '' ? null : leaf.field}
                onChange={changeField}
                placeholder="Campo…"
                allowCustom={allowUnknownFields}
                disabled={disabled}
                className="w-56"
            />

            <Select
                value={leaf.operator}
                onValueChange={changeOperator}
                disabled={disabled || leaf.field === ''}
            >
                <SelectTrigger className="h-8 w-44 text-xs">
                    <SelectValue placeholder="Operador" />
                </SelectTrigger>
                <SelectContent>
                    {operators.map((operator) => (
                        <SelectItem key={operator} value={operator}>
                            {OPERATOR_LABELS[operator] ?? operator}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <ValueInput
                field={fieldDef}
                operator={leaf.operator}
                value={leaf.value}
                onChange={(value) => onChange({ ...leaf, value })}
                disabled={disabled}
            />

            {!disabled && (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-7 text-fg-3 hover:text-destructive"
                    onClick={onRemove}
                    aria-label="Quitar condición"
                >
                    <Trash2 className="size-3.5" />
                </Button>
            )}
        </div>
    );
}
