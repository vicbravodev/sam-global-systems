import { X } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { LIST_OPERATORS, NO_VALUE_OPERATORS } from './lib';
import type { ConditionFieldDef, ScalarValue } from './types';

/**
 * Input polimórfico para el valor de una condición: Select para enum y
 * boolean, número/texto para escalares, multi-badges para in/not_in, y
 * nada para is_null/is_not_null.
 */

interface ValueInputProps {
    field: ConditionFieldDef | null;
    operator: string;
    value: unknown;
    onChange: (value: unknown) => void;
    disabled?: boolean;
}

const BOOLEAN_OPTIONS = [
    { value: 'true', label: 'Sí' },
    { value: 'false', label: 'No' },
];

export function ValueInput({
    field,
    operator,
    value,
    onChange,
    disabled,
}: ValueInputProps) {
    if (NO_VALUE_OPERATORS.includes(operator)) {
        return null;
    }

    if (LIST_OPERATORS.includes(operator)) {
        return (
            <ListValueInput
                field={field}
                value={Array.isArray(value) ? (value as ScalarValue[]) : []}
                onChange={onChange}
                disabled={disabled}
            />
        );
    }

    if (field?.type === 'boolean') {
        return (
            <Select
                value={value === true ? 'true' : value === false ? 'false' : ''}
                onValueChange={(next) => onChange(next === 'true')}
                disabled={disabled}
            >
                <SelectTrigger className="h-8 w-32 text-xs">
                    <SelectValue placeholder="Valor" />
                </SelectTrigger>
                <SelectContent>
                    {BOOLEAN_OPTIONS.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        );
    }

    if (field?.type === 'enum' && field.options.length > 0) {
        return (
            <Select
                value={typeof value === 'string' ? value : ''}
                onValueChange={onChange}
                disabled={disabled}
            >
                <SelectTrigger className="h-8 min-w-44 text-xs">
                    <SelectValue placeholder="Valor" />
                </SelectTrigger>
                <SelectContent>
                    {field.options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        );
    }

    if (field?.type === 'number') {
        return (
            <Input
                type="number"
                step="any"
                value={
                    typeof value === 'number' || typeof value === 'string'
                        ? String(value)
                        : ''
                }
                onChange={(e) =>
                    onChange(
                        e.target.value === '' ? '' : Number(e.target.value),
                    )
                }
                placeholder="0"
                disabled={disabled}
                className="h-8 w-28 text-xs tabular-nums"
            />
        );
    }

    return (
        <Input
            value={typeof value === 'string' ? value : String(value ?? '')}
            onChange={(e) => onChange(e.target.value)}
            placeholder="Valor"
            disabled={disabled}
            className="h-8 w-44 text-xs"
        />
    );
}

function ListValueInput({
    field,
    value,
    onChange,
    disabled,
}: {
    field: ConditionFieldDef | null;
    value: ScalarValue[];
    onChange: (value: ScalarValue[]) => void;
    disabled?: boolean;
}) {
    const [draft, setDraft] = useState('');

    const add = (raw: string) => {
        const item =
            field?.type === 'number' && raw !== '' && !Number.isNaN(Number(raw))
                ? Number(raw)
                : raw;

        if (raw.trim() === '' || value.includes(item)) {
            return;
        }

        onChange([...value, item]);
        setDraft('');
    };

    const remove = (item: ScalarValue) => {
        onChange(value.filter((existing) => existing !== item));
    };

    const labelFor = (item: ScalarValue): string => {
        const match = field?.options.find(
            (option) => option.value === String(item),
        );

        return match?.label ?? String(item);
    };

    const remaining =
        field?.options.filter((option) => !value.includes(option.value)) ?? [];

    return (
        <div className="flex min-w-0 flex-wrap items-center gap-1.5">
            {value.map((item) => (
                <Badge
                    key={String(item)}
                    variant="secondary"
                    className="gap-1 pr-1 text-xs"
                >
                    {labelFor(item)}
                    {!disabled && (
                        <button
                            type="button"
                            onClick={() => remove(item)}
                            aria-label={`Quitar ${labelFor(item)}`}
                            className="rounded-sm p-0.5 hover:text-destructive"
                        >
                            <X className="size-3" />
                        </button>
                    )}
                </Badge>
            ))}
            {!disabled &&
                (field?.type === 'enum' && field.options.length > 0 ? (
                    remaining.length > 0 && (
                        <Select value="" onValueChange={(next) => add(next)}>
                            <SelectTrigger className="h-7 w-36 text-xs">
                                <SelectValue placeholder="Añadir…" />
                            </SelectTrigger>
                            <SelectContent>
                                {remaining.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )
                ) : (
                    <Input
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                add(draft);
                            }
                        }}
                        onBlur={() => add(draft)}
                        placeholder="Valor + Enter"
                        className="h-7 w-32 text-xs"
                    />
                ))}
        </div>
    );
}
