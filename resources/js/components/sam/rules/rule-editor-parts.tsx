import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** Paso numerado del editor guiado: número, pregunta, ayuda y control. */
export function EditorStep({
    step,
    title,
    help,
    children,
    className,
}: {
    step: number;
    title: string;
    help?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('flex flex-col gap-3', className)}>
            <div className="flex items-start gap-2.5">
                <span
                    className="mt-px grid size-5 shrink-0 place-items-center rounded-full bg-primary/10 text-2xs font-semibold text-primary"
                    aria-hidden="true"
                >
                    {step}
                </span>
                <div className="flex min-w-0 flex-col gap-0.5">
                    <h3 className="text-sm font-semibold text-fg-1">{title}</h3>
                    {help && (
                        <p className="text-xs leading-relaxed text-fg-3">
                            {help}
                        </p>
                    )}
                </div>
            </div>
            <div className="flex min-w-0 flex-col gap-2 sm:pl-7">
                {children}
            </div>
        </section>
    );
}

/** Aviso de solo lectura dentro de un editor. */
export function ReadOnlyNote({ children }: { children: ReactNode }) {
    return (
        <p className="rounded-md border border-border bg-surface-2 px-3 py-2 text-xs leading-relaxed text-fg-2">
            {children}
        </p>
    );
}
