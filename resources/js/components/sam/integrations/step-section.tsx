import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

interface Props {
    step: number;
    title: string;
    description?: string;
    done?: boolean;
    children: ReactNode;
}

/** Numbered step inside a guided dialog ("1 · ¿Qué proveedor usas?"). */
export function StepSection({
    step,
    title,
    description,
    done = false,
    children,
}: Props) {
    return (
        <section className="flex gap-3">
            <span
                className={cn(
                    'grid size-6 shrink-0 place-items-center rounded-full border text-2xs font-semibold tabular-nums',
                    done
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-border bg-surface-2 text-fg-2',
                )}
                aria-hidden="true"
            >
                {step}
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                <div className="flex flex-col gap-0.5 pt-0.5">
                    <h3 className="text-sm font-semibold text-fg-1">{title}</h3>
                    {description ? (
                        <p className="text-xs leading-relaxed text-fg-3">
                            {description}
                        </p>
                    ) : null}
                </div>
                {children}
            </div>
        </section>
    );
}
