import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export interface PanelProps {
    title: ReactNode;
    /** Una línea que dice qué es el bloque y qué hacer aquí. */
    description?: ReactNode;
    /** Acción alineada a la derecha del encabezado. */
    action?: ReactNode;
    children: ReactNode;
    /** `lg` usa el encabezado `sam-h3` (consola de super-admin). */
    size?: 'default' | 'lg';
    className?: string;
    bodyClassName?: string;
}

/**
 * Superficie con borde y encabezado (título, descripción y acción opcional)
 * separado del cuerpo por una línea. El cuerpo no lleva padding: pásalo en
 * `bodyClassName` cuando el contenido no sea una lista a sangre.
 */
export function Panel({
    title,
    description,
    action,
    children,
    size = 'default',
    className,
    bodyClassName,
}: PanelProps) {
    return (
        <section
            className={cn(
                'flex min-w-0 flex-col rounded-lg border border-border bg-surface-1',
                className,
            )}
        >
            <header className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 border-b border-border px-4 py-3">
                <div className="min-w-0">
                    <h2 className={size === 'lg' ? 'sam-h3' : 'sam-h4'}>
                        {title}
                    </h2>
                    {description && (
                        <p className="mt-0.5 text-xs text-fg-3">
                            {description}
                        </p>
                    )}
                </div>
                {action && <div className="shrink-0">{action}</div>}
            </header>
            <div className={cn('flex flex-col', bodyClassName)}>{children}</div>
        </section>
    );
}
