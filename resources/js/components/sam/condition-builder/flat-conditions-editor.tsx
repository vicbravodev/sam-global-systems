import { Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import type { ConditionFieldDef, ScalarValue } from './types';
import { ValueInput } from './value-input';

/**
 * Editor del dict plano `{campo: valor}` (AND de igualdades): una fila por
 * condición, campo desde catálogo (o texto libre para dot-paths) y valor
 * tipado según el campo.
 */

export interface FlatRow {
    id: string;
    key: string;
    value: ScalarValue;
}

interface FlatConditionsEditorProps {
    rows: FlatRow[];
    fields: ConditionFieldDef[];
    allowUnknownFields?: boolean;
    disabled?: boolean;
    onChange: (rows: FlatRow[]) => void;
    onAdd: () => void;
}

export function FlatConditionsEditor({
    rows,
    fields,
    allowUnknownFields = false,
    disabled,
    onChange,
    onAdd,
}: FlatConditionsEditorProps) {
    const replace = (index: number, row: FlatRow) => {
        const next = [...rows];
        next[index] = row;
        onChange(next);
    };

    return (
        <div className="flex flex-col gap-2">
            {rows.length === 0 && (
                <p className="text-xs text-fg-3">
                    Sin condiciones: aplica siempre.
                </p>
            )}

            {rows.map((row, index) => {
                const fieldDef =
                    fields.find((field) => field.key === row.key) ?? null;

                return (
                    <div
                        key={row.id}
                        className="flex flex-wrap items-center gap-2"
                    >
                        <Combobox
                            options={fields.map((field) => ({
                                value: field.key,
                                label: field.label,
                            }))}
                            value={row.key === '' ? null : row.key}
                            onChange={(key) =>
                                replace(index, {
                                    ...row,
                                    key: key ?? '',
                                    value: '',
                                })
                            }
                            placeholder={
                                allowUnknownFields
                                    ? 'Campo o ruta (data.alert.type)…'
                                    : 'Campo…'
                            }
                            allowCustom={allowUnknownFields}
                            disabled={disabled}
                            className="w-64"
                        />
                        <span className="text-xs text-fg-3">es igual a</span>
                        <ValueInput
                            field={fieldDef}
                            operator="eq"
                            value={row.value}
                            onChange={(value) =>
                                replace(index, {
                                    ...row,
                                    value: value as ScalarValue,
                                })
                            }
                            disabled={disabled}
                        />
                        {!disabled && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-7 text-fg-3 hover:text-destructive"
                                onClick={() =>
                                    onChange(rows.filter((_, i) => i !== index))
                                }
                                aria-label="Quitar condición"
                            >
                                <Trash2 className="size-3.5" />
                            </Button>
                        )}
                    </div>
                );
            })}

            {!disabled && (
                <div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-7 text-xs text-fg-2"
                        onClick={onAdd}
                    >
                        <Plus className="size-3.5" />
                        Condición
                    </Button>
                </div>
            )}
        </div>
    );
}
