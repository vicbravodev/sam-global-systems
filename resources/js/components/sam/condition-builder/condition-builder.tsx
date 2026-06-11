import { ChevronRight, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { ConditionGroup } from './condition-group';
import { FlatConditionsEditor } from './flat-conditions-editor';
import type { FlatRow } from './flat-conditions-editor';
import {
    makeGroup,
    newFlatRow,
    parseFlat,
    parseTree,
    serializeFlat,
    serializeTree,
} from './lib';
import type { ConditionFieldDef, VisualGroup } from './types';

/**
 * Builder visual de condiciones con dos variantes:
 * - `tree`: árbol all/any del motor de decisiones.
 * - `flat-equality`: dict plano `{campo: valor}` (mapeo, automation,
 *   escalación).
 *
 * El modo avanzado (JSON) se sincroniza con el visual en ambas direcciones.
 * Un JSON válido pero no representable visualmente nunca se pierde: se
 * muestra el aviso de estructura avanzada y se edita solo en JSON.
 */

interface ConditionBuilderProps {
    variant: 'tree' | 'flat-equality';
    fields: ConditionFieldDef[];
    value: Record<string, unknown>;
    onChange: (value: Record<string, unknown>) => void;
    allowUnknownFields?: boolean;
    disabled?: boolean;
    className?: string;
}

export function ConditionBuilder({
    variant,
    fields,
    value,
    onChange,
    allowUnknownFields = false,
    disabled = false,
    className,
}: ConditionBuilderProps) {
    const [tree, setTree] = useState<VisualGroup | null>(() =>
        variant === 'tree' ? parseTree(value) : null,
    );
    const [rows, setRows] = useState<FlatRow[] | null>(() =>
        variant === 'flat-equality' ? parseFlat(value) : null,
    );
    const [jsonOpen, setJsonOpen] = useState(false);
    const [jsonDraft, setJsonDraft] = useState(() =>
        JSON.stringify(value, null, 2),
    );
    const [jsonError, setJsonError] = useState<string | null>(null);

    // Última serialización conocida por este componente: si `value` llega
    // distinta, el cambio vino de fuera y se re-parsea durante el render
    // (patrón "adjusting state during render" de React).
    const [synced, setSynced] = useState(() => JSON.stringify(value));
    const incoming = JSON.stringify(value);

    if (incoming !== synced) {
        setSynced(incoming);

        if (variant === 'tree') {
            setTree(parseTree(value));
        } else {
            setRows(parseFlat(value));
        }

        setJsonDraft(JSON.stringify(value, null, 2));
        setJsonError(null);
    }

    const emit = (next: Record<string, unknown>) => {
        setSynced(JSON.stringify(next));
        setJsonDraft(JSON.stringify(next, null, 2));
        setJsonError(null);
        onChange(next);
    };

    const changeTree = (next: VisualGroup) => {
        setTree(next);
        emit(serializeTree(next));
    };

    const changeRows = (next: FlatRow[]) => {
        setRows(next);
        emit(serializeFlat(next));
    };

    const changeJson = (raw: string) => {
        setJsonDraft(raw);

        let parsed: unknown;

        try {
            parsed = JSON.parse(raw);
        } catch {
            setJsonError('JSON inválido: revisa la sintaxis.');

            return;
        }

        if (
            parsed === null ||
            typeof parsed !== 'object' ||
            Array.isArray(parsed)
        ) {
            setJsonError('Las condiciones deben ser un objeto JSON.');

            return;
        }

        setJsonError(null);

        const next = parsed as Record<string, unknown>;

        setSynced(JSON.stringify(next));
        onChange(next);

        if (variant === 'tree') {
            setTree(parseTree(next));
        } else {
            setRows(parseFlat(next));
        }
    };

    const representable = variant === 'tree' ? tree !== null : rows !== null;

    return (
        <div className={cn('flex flex-col gap-3', className)}>
            {!representable && (
                <div className="flex items-start gap-2 rounded-md border border-border bg-surface-2 p-2.5 text-xs text-fg-2">
                    <TriangleAlert
                        className="mt-0.5 size-3.5 shrink-0 text-severity-medium"
                        aria-hidden
                    />
                    <span>
                        Estructura avanzada: estas condiciones usan elementos
                        que el editor visual no representa. Edítalas en modo
                        JSON; no se perderá ningún dato.
                    </span>
                </div>
            )}

            {representable &&
                (variant === 'tree' ? (
                    <ConditionGroup
                        group={tree ?? makeGroup()}
                        fields={fields}
                        allowUnknownFields={allowUnknownFields}
                        disabled={disabled}
                        onChange={changeTree}
                    />
                ) : (
                    <FlatConditionsEditor
                        rows={rows ?? []}
                        fields={fields}
                        allowUnknownFields={allowUnknownFields}
                        disabled={disabled}
                        onChange={changeRows}
                        onAdd={() =>
                            changeRows([...(rows ?? []), newFlatRow()])
                        }
                    />
                ))}

            <div>
                <button
                    type="button"
                    onClick={() => setJsonOpen(!jsonOpen)}
                    className="flex items-center gap-1 text-xs text-fg-3 transition-colors hover:text-fg-1"
                    aria-expanded={jsonOpen || !representable}
                >
                    <ChevronRight
                        className={cn(
                            'size-3.5 transition-transform',
                            (jsonOpen || !representable) && 'rotate-90',
                        )}
                        aria-hidden
                    />
                    Modo avanzado (JSON)
                </button>

                {(jsonOpen || !representable) && (
                    <div className="mt-2 flex flex-col gap-1">
                        <Textarea
                            value={jsonDraft}
                            onChange={(e) => changeJson(e.target.value)}
                            rows={6}
                            spellCheck={false}
                            disabled={disabled}
                            aria-invalid={jsonError !== null}
                            className="bg-surface-2 font-mono text-xs"
                        />
                        {jsonError && (
                            <p className="text-xs text-destructive">
                                {jsonError}
                            </p>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
