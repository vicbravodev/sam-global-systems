import {
    Combobox as HeadlessCombobox,
    ComboboxButton,
    ComboboxInput,
    ComboboxOption,
    ComboboxOptions,
} from '@headlessui/react';
import { Check, ChevronsUpDown } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Combobox (select buscable) sobre @headlessui/react. Single-select con
 * filtrado por texto; `allowCustom` admite valores libres no presentes en
 * las opciones (p.ej. dot-paths de payloads).
 */

export interface ComboboxOptionItem {
    value: string;
    label: string;
    description?: string;
}

interface ComboboxProps {
    options: ComboboxOptionItem[];
    value: string | null;
    onChange: (value: string | null) => void;
    placeholder?: string;
    /** Permite escribir un valor que no está en las opciones. */
    allowCustom?: boolean;
    disabled?: boolean;
    emptyText?: string;
    className?: string;
    id?: string;
    'aria-invalid'?: boolean;
    'aria-label'?: string;
}

function Combobox({
    options,
    value,
    onChange,
    placeholder = 'Selecciona una opción',
    allowCustom = false,
    disabled = false,
    emptyText = 'Sin resultados',
    className,
    id,
    ...props
}: ComboboxProps) {
    const [query, setQuery] = React.useState('');

    const filtered =
        query === ''
            ? options
            : options.filter(
                  (option) =>
                      option.label
                          .toLowerCase()
                          .includes(query.toLowerCase()) ||
                      option.value.toLowerCase().includes(query.toLowerCase()),
              );

    const showCustom =
        allowCustom &&
        query.trim() !== '' &&
        !options.some((option) => option.value === query.trim());

    return (
        <HeadlessCombobox
            value={value}
            onChange={onChange}
            onClose={() => setQuery('')}
            disabled={disabled}
            immediate
        >
            <div className={cn('relative', className)}>
                <ComboboxInput
                    id={id}
                    aria-invalid={props['aria-invalid']}
                    aria-label={props['aria-label']}
                    className={cn(
                        'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 pr-8 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground md:text-sm',
                        'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                        'aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40',
                        'disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50',
                    )}
                    displayValue={(current: string | null) =>
                        options.find((option) => option.value === current)
                            ?.label ??
                        current ??
                        ''
                    }
                    placeholder={placeholder}
                    onChange={(event) => {
                        setQuery(event.target.value);

                        if (allowCustom) {
                            onChange(event.target.value || null);
                        }
                    }}
                />
                <ComboboxButton className="absolute inset-y-0 right-0 flex items-center pr-2.5 text-fg-3">
                    <ChevronsUpDown className="size-4" aria-hidden />
                </ComboboxButton>
            </div>

            <ComboboxOptions
                anchor="bottom start"
                transition
                className={cn(
                    'z-50 w-(--input-width) overflow-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md [--anchor-gap:4px] [--anchor-max-height:280px] empty:invisible',
                    'origin-top transition duration-150 ease-out data-closed:scale-[0.97] data-closed:opacity-0',
                )}
            >
                {filtered.map((option) => (
                    <ComboboxOption
                        key={option.value}
                        value={option.value}
                        className={cn(
                            'relative flex w-full cursor-default items-center gap-2 rounded-sm py-1.5 pr-8 pl-2 text-sm outline-none select-none',
                            'data-focus:bg-accent data-focus:text-accent-foreground',
                        )}
                    >
                        <div className="min-w-0 flex-1">
                            <span className="block truncate">
                                {option.label}
                            </span>
                            {option.description ? (
                                <span className="block truncate text-xs text-fg-3">
                                    {option.description}
                                </span>
                            ) : null}
                        </div>
                        {value === option.value ? (
                            <span className="absolute right-2 flex size-3.5 items-center justify-center">
                                <Check className="size-4" aria-hidden />
                            </span>
                        ) : null}
                    </ComboboxOption>
                ))}

                {showCustom ? (
                    <ComboboxOption
                        value={query.trim()}
                        className="relative flex w-full cursor-default items-center gap-2 rounded-sm py-1.5 pr-8 pl-2 text-sm outline-none select-none data-focus:bg-accent data-focus:text-accent-foreground"
                    >
                        <span className="truncate">Usar «{query.trim()}»</span>
                    </ComboboxOption>
                ) : null}

                {filtered.length === 0 && !showCustom ? (
                    <div className="px-2 py-1.5 text-sm text-fg-3">
                        {emptyText}
                    </div>
                ) : null}
            </ComboboxOptions>
        </HeadlessCombobox>
    );
}

export { Combobox };
