import { Plus, X } from 'lucide-react';

import { cn } from '@/lib/utils';

export interface FilterChipProps {
    /** Filter dimension, e.g. 'Severidad'. */
    label: string;
    /** Selected value, e.g. 'Crítica'. Omit for a label-only chip. */
    value?: string;
    onRemove: () => void;
    className?: string;
}

/** A removable applied-filter pill (label: value + ✕). */
export function FilterChip({
    label,
    value,
    onRemove,
    className,
}: FilterChipProps) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border border-border bg-surface-1 px-2 py-1 text-2xs font-medium text-fg-1',
                className,
            )}
        >
            <span className="text-fg-3">{label}</span>
            {value && <span>{value}</span>}
            <button
                type="button"
                onClick={onRemove}
                aria-label={
                    value
                        ? `Quitar filtro ${label}: ${value}`
                        : `Quitar filtro ${label}`
                }
                className="grid place-items-center text-fg-3 transition-colors hover:text-fg-1"
            >
                <X className="size-3" />
            </button>
        </span>
    );
}

export interface AddFilterButtonProps {
    onClick: () => void;
    label?: string;
    className?: string;
}

/** Dashed-outline "add filter" trigger. */
export function AddFilterButton({
    onClick,
    label = 'Agregar filtro',
    className,
}: AddFilterButtonProps) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border border-dashed border-border-strong px-2 py-1 text-2xs font-medium text-fg-3 transition-colors hover:text-fg-1',
                className,
            )}
        >
            <Plus className="size-3" />
            {label}
        </button>
    );
}
