import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export interface FieldProps {
    label: string;
    /** Helper text under the label. */
    help?: string;
    /** Associates the label with a control id for a11y. */
    htmlFor?: string;
    children: ReactNode;
    className?: string;
}

/**
 * Two-column settings field: a 240px label/help column and a control column,
 * stacking below 760px. Matches the design system .sam-field layout.
 */
export function Field({
    label,
    help,
    htmlFor,
    children,
    className,
}: FieldProps) {
    return (
        <div
            className={cn(
                'grid grid-cols-1 items-start gap-2 sm:grid-cols-[240px_1fr] sm:gap-4',
                className,
            )}
        >
            <label htmlFor={htmlFor} className="flex flex-col gap-0.5">
                <span className="text-sm font-semibold text-fg-1">{label}</span>
                {help && (
                    <span className="text-2xs leading-relaxed text-fg-3">
                        {help}
                    </span>
                )}
            </label>
            <div className="flex min-w-0 flex-col gap-2">{children}</div>
        </div>
    );
}

export interface FormCardProps {
    children: ReactNode;
    className?: string;
}

/** Bordered surface that groups Fields, max-width for readable forms. */
export function FormCard({ children, className }: FormCardProps) {
    return (
        <div
            className={cn(
                'flex max-w-3xl flex-col gap-4 rounded-lg border border-border bg-surface-1 p-5',
                className,
            )}
        >
            {children}
        </div>
    );
}
