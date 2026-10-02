import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export interface StepProps {
    step: number;
    title: string;
    help?: ReactNode;
    /** Marca el número como completado (relleno primario). */
    done?: boolean;
    children: ReactNode;
    className?: string;
}

/**
 * Paso numerado de un editor o diálogo guiado: número, pregunta, ayuda y
 * control debajo, alineado con el texto.
 */
export function Step({
    step,
    title,
    help,
    done = false,
    children,
    className,
}: StepProps) {
    return (
        <section className={cn('flex flex-col gap-3', className)}>
            <div className="flex items-start gap-2.5">
                <span
                    className={cn(
                        'mt-px grid size-5 shrink-0 place-items-center rounded-full text-2xs font-semibold tabular-nums',
                        done
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-primary/10 text-primary',
                    )}
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
