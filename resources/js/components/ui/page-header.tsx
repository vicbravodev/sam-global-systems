import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Header de página único para toda la superficie: título + meta + acciones.
 * Toda página nueva usa este patrón; no inventar headers ad-hoc.
 */

interface PageHeaderProps extends React.ComponentProps<'header'> {
    title: string;
    /** Descripción corta debajo del título. */
    description?: string;
    /** Meta inline junto al título (conteos, badges de estado). */
    meta?: React.ReactNode;
    /** Acciones alineadas a la derecha (botones, filtros rápidos). */
    actions?: React.ReactNode;
}

function PageHeader({
    className,
    title,
    description,
    meta,
    actions,
    ...props
}: PageHeaderProps) {
    return (
        <header
            data-slot="page-header"
            className={cn(
                'flex flex-wrap items-start justify-between gap-x-4 gap-y-2',
                className,
            )}
            {...props}
        >
            <div className="min-w-0">
                {/* D4: min-w-0 en el contenedor flex para que truncate funcione
                    cuando hay meta al lado en pantallas estrechas. */}
                <div className="flex min-w-0 items-center gap-2.5">
                    <h1 className="sam-h2 min-w-0 truncate">{title}</h1>
                    {meta ? (
                        <div className="flex shrink-0 items-center gap-1.5">
                            {meta}
                        </div>
                    ) : null}
                </div>
                {description ? (
                    <p className="text-fg-3 mt-1 text-sm">{description}</p>
                ) : null}
            </div>
            {actions ? (
                <div className="flex shrink-0 items-center gap-2">
                    {actions}
                </div>
            ) : null}
        </header>
    );
}

export { PageHeader };
