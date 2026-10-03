import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export interface FormFieldProps {
    label: ReactNode;
    /** Id del control, para asociar la etiqueta. */
    htmlFor?: string;
    /** Ayuda bajo el control. */
    help?: ReactNode;
    /** Mensaje de validación del backend. */
    error?: string;
    /** `sm` para hojas y diálogos densos (etiqueta y error en `text-xs`). */
    size?: 'default' | 'sm';
    children: ReactNode;
    className?: string;
}

/**
 * Campo apilado: etiqueta, control, ayuda y error. Para formularios en dos
 * columnas de ajustes usa `Field`.
 */
export function FormField({
    label,
    htmlFor,
    help,
    error,
    size = 'default',
    children,
    className,
}: FormFieldProps) {
    const small = size === 'sm';

    return (
        <div className={cn('grid gap-1.5', className)}>
            <Label htmlFor={htmlFor} className={small ? 'text-xs' : undefined}>
                {label}
            </Label>
            {children}
            {help && <p className="text-xs text-fg-3">{help}</p>}
            <InputError
                message={error}
                className={small ? 'text-xs' : undefined}
            />
        </div>
    );
}
