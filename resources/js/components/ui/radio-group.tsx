import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Native-input radio group (no Radix dependency available for radio in this
 * repo). Visuals match the checkbox primitive; keyboard behavior is the
 * browser's own radio roving focus.
 */

interface RadioGroupContextValue {
    name: string;
    value?: string;
    onValueChange?: (value: string) => void;
    disabled?: boolean;
}

const RadioGroupContext = React.createContext<RadioGroupContextValue | null>(
    null,
);

interface RadioGroupProps extends Omit<
    React.ComponentProps<'div'>,
    'onChange' | 'defaultValue'
> {
    name?: string;
    value?: string;
    defaultValue?: string;
    onValueChange?: (value: string) => void;
    disabled?: boolean;
}

function RadioGroup({
    className,
    name,
    value,
    defaultValue,
    onValueChange,
    disabled,
    ...props
}: RadioGroupProps) {
    const autoName = React.useId();
    const [internal, setInternal] = React.useState(defaultValue);
    const controlled = value !== undefined;
    const current = controlled ? value : internal;

    const handleChange = (next: string) => {
        if (!controlled) {
            setInternal(next);
        }
        onValueChange?.(next);
    };

    return (
        <RadioGroupContext.Provider
            value={{
                name: name ?? autoName,
                value: current,
                onValueChange: handleChange,
                disabled,
            }}
        >
            <div
                role="radiogroup"
                data-slot="radio-group"
                className={cn('grid gap-2', className)}
                {...props}
            />
        </RadioGroupContext.Provider>
    );
}

interface RadioGroupItemProps
    extends Omit<React.ComponentProps<'input'>, 'type' | 'value'> {
    value: string;
}

function RadioGroupItem({
    className,
    value,
    disabled,
    ...props
}: RadioGroupItemProps) {
    const ctx = React.useContext(RadioGroupContext);

    return (
        <input
            type="radio"
            data-slot="radio-group-item"
            name={ctx?.name}
            value={value}
            checked={ctx?.value !== undefined ? ctx.value === value : undefined}
            onChange={() => ctx?.onValueChange?.(value)}
            disabled={disabled ?? ctx?.disabled}
            className={cn(
                'border-input text-primary size-4 shrink-0 appearance-none rounded-full border shadow-xs transition-[color,box-shadow] outline-none',
                'checked:border-primary checked:bg-primary checked:shadow-[inset_0_0_0_3px_var(--color-background)]',
                'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]',
                'disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50',
                className,
            )}
            {...props}
        />
    );
}

export { RadioGroup, RadioGroupItem };
