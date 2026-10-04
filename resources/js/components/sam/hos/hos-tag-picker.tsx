import { useState } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import type { HosTagOption } from '@/types/hos';
import { tagMembersLabel } from './config-lib';
import type { HosTagsState } from './use-hos-tags';

const DEPTH_INDENT = ['pl-3', 'pl-8', 'pl-12'];

export interface HosTagPickerProps {
    tags: HosTagsState;
    selected: string[];
    disabled: boolean;
    onChange: (next: string[]) => void;
}

/** Etiquetas de Samsara con casillas; elegir una madre incluye a sus hijas. */
export function HosTagPicker({
    tags,
    selected,
    disabled,
    onChange,
}: HosTagPickerProps) {
    const [query, setQuery] = useState('');

    if (tags.status === 'loading') {
        return (
            <div
                className="flex flex-col gap-2"
                aria-busy="true"
                aria-label="Cargando etiquetas"
            >
                <Skeleton className="h-8 w-full" />
                <Skeleton className="h-32 w-full rounded-md" />
            </div>
        );
    }

    if (tags.status === 'throttled') {
        return (
            <p className="text-xs text-fg-3" role="status">
                Hiciste muchas consultas seguidas. Espera un minuto y recarga la
                página; lo que ya elegiste se conserva.
            </p>
        );
    }

    if (tags.status === 'error' || (tags.failed && tags.tags.length === 0)) {
        return (
            <p className="text-xs text-fg-3">
                No pudimos leer las etiquetas de Samsara. Vuelve a intentarlo en
                unos minutos; lo que ya elegiste se conserva.
            </p>
        );
    }

    if (!tags.hasIntegration) {
        return (
            <p className="text-xs text-fg-3">
                Conecta tu integración con Samsara para elegir etiquetas.
            </p>
        );
    }

    const known = new Set(tags.tags.map((tag) => tag.id));
    const missing = selected.filter((id) => !known.has(id));
    const needle = query.trim().toLowerCase();
    const visible =
        needle === ''
            ? tags.tags
            : tags.tags.filter((tag) =>
                  tag.name.toLowerCase().includes(needle),
              );
    const toggle = (id: string) =>
        onChange(
            selected.includes(id)
                ? selected.filter((value) => value !== id)
                : [...selected, id],
        );

    return (
        <div className="flex flex-col gap-2">
            <Input
                type="search"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder="Buscar etiqueta…"
                aria-label="Buscar etiqueta"
                className="h-8"
            />
            <ul className="max-h-72 overflow-y-auto rounded-md border border-border bg-surface-2">
                {missing.map((id) => (
                    <MissingTagRow
                        key={id}
                        id={id}
                        partial={tags.failed}
                        disabled={disabled}
                        onToggle={() => toggle(id)}
                    />
                ))}
                {visible.map((tag) => (
                    <TagRow
                        key={tag.id}
                        tag={tag}
                        checked={selected.includes(tag.id)}
                        disabled={disabled}
                        onToggle={() => toggle(tag.id)}
                    />
                ))}
                {visible.length === 0 && missing.length === 0 ? (
                    <li className="px-3 py-4 text-center text-xs text-fg-3">
                        Sin etiquetas que coincidan.
                    </li>
                ) : null}
            </ul>
            {tags.failed ? (
                <p className="text-2xs text-fg-3" role="status">
                    Samsara no respondió del todo: puede faltar alguna etiqueta.
                    Vuelve a intentarlo en unos minutos.
                </p>
            ) : null}
            <p className="text-2xs text-fg-3">
                Elegir una etiqueta incluye también sus subetiquetas.
            </p>
        </div>
    );
}

function TagRow({
    tag,
    checked,
    disabled,
    onToggle,
}: {
    tag: HosTagOption;
    checked: boolean;
    disabled: boolean;
    onToggle: () => void;
}) {
    const id = `hos-tag-${tag.id}`;

    return (
        <li
            className={cn(
                'flex items-center gap-2 border-b border-border py-2 pr-3 last:border-b-0',
                DEPTH_INDENT[Math.min(tag.depth, DEPTH_INDENT.length - 1)],
            )}
        >
            <Checkbox
                id={id}
                checked={checked}
                disabled={disabled}
                onCheckedChange={onToggle}
            />
            <label
                htmlFor={id}
                className="flex min-w-0 flex-1 items-center justify-between gap-2 text-sm text-fg-1"
            >
                <span className="truncate">{tag.name}</span>
                <span className="shrink-0 text-2xs text-fg-3">
                    {tagMembersLabel(tag)}
                </span>
            </label>
        </li>
    );
}

function MissingTagRow({
    id,
    partial,
    disabled,
    onToggle,
}: {
    id: string;
    /** Samsara no respondió completo: quizá sí existe. */
    partial: boolean;
    disabled: boolean;
    onToggle: () => void;
}) {
    const inputId = `hos-tag-missing-${id}`;

    return (
        <li className="flex items-center gap-2 border-b border-border py-2 pr-3 pl-3">
            <Checkbox
                id={inputId}
                checked
                disabled={disabled}
                onCheckedChange={onToggle}
            />
            <label htmlFor={inputId} className="text-sm text-fg-1">
                Etiqueta {id}{' '}
                <span className="text-fg-3">
                    {partial
                        ? '(no la encontramos en Samsara)'
                        : '(ya no existe en Samsara)'}
                </span>
            </label>
        </li>
    );
}
