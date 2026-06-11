import type { LucideIcon } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Empty state que enseña: icono, título, descripción y CTA opcional.
 * Para listas vacías usar siempre este patrón en vez de "no hay nada".
 */

interface EmptyStateProps extends React.ComponentProps<'div'> {
    icon?: LucideIcon;
    title: string;
    description?: string;
    /** CTA — normalmente un <Button> o <Link>. */
    action?: React.ReactNode;
}

function EmptyState({
    className,
    icon: Icon,
    title,
    description,
    action,
    ...props
}: EmptyStateProps) {
    return (
        <div
            data-slot="empty-state"
            className={cn(
                'flex flex-col items-center justify-center gap-1 px-6 py-12 text-center',
                className,
            )}
            {...props}
        >
            {Icon ? (
                <div className="bg-surface-2 text-fg-3 mb-3 grid size-10 place-items-center rounded-lg">
                    <Icon className="size-5" aria-hidden />
                </div>
            ) : null}
            <p className="text-fg-1 text-sm font-medium">{title}</p>
            {description ? (
                <p className="text-fg-3 max-w-sm text-sm">{description}</p>
            ) : null}
            {action ? <div className="mt-4">{action}</div> : null}
        </div>
    );
}

export { EmptyState };
