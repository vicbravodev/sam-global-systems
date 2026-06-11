import { ChevronLeft, ChevronRight, MoreHorizontal } from 'lucide-react';
import * as React from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * Paginación client-driven: el consumidor pasa la página actual, el total y
 * un callback. Renderiza primera/última, vecinas y elipsis; labels en español.
 */

interface PaginationProps extends Omit<React.ComponentProps<'nav'>, 'onChange'> {
    page: number;
    totalPages: number;
    onPageChange: (page: number) => void;
    /** Páginas vecinas visibles a cada lado de la actual. */
    siblings?: number;
}

function pageItems(
    page: number,
    totalPages: number,
    siblings: number,
): (number | 'ellipsis')[] {
    if (totalPages <= 5 + siblings * 2) {
        return Array.from({ length: totalPages }, (_, i) => i + 1);
    }

    const items: (number | 'ellipsis')[] = [1];
    const start = Math.max(2, page - siblings);
    const end = Math.min(totalPages - 1, page + siblings);

    if (start > 2) {
        items.push('ellipsis');
    }
    for (let i = start; i <= end; i++) {
        items.push(i);
    }
    if (end < totalPages - 1) {
        items.push('ellipsis');
    }
    items.push(totalPages);

    return items;
}

function Pagination({
    className,
    page,
    totalPages,
    onPageChange,
    siblings = 1,
    ...props
}: PaginationProps) {
    if (totalPages <= 1) {
        return null;
    }

    return (
        <nav
            role="navigation"
            aria-label="Paginación"
            data-slot="pagination"
            className={cn('flex items-center justify-center gap-1', className)}
            {...props}
        >
            <Button
                variant="ghost"
                size="sm"
                disabled={page <= 1}
                onClick={() => onPageChange(page - 1)}
                aria-label="Página anterior"
            >
                <ChevronLeft />
                <span className="hidden sm:inline">Anterior</span>
            </Button>

            {pageItems(page, totalPages, siblings).map((item, idx) =>
                item === 'ellipsis' ? (
                    <span
                        key={`ellipsis-${idx}`}
                        aria-hidden
                        className="text-fg-3 flex size-8 items-end justify-center pb-1"
                    >
                        <MoreHorizontal className="size-4" />
                    </span>
                ) : (
                    <Button
                        key={item}
                        variant={item === page ? 'outline' : 'ghost'}
                        size="icon"
                        className="size-8 tabular-nums"
                        aria-current={item === page ? 'page' : undefined}
                        aria-label={`Página ${item}`}
                        onClick={() => onPageChange(item)}
                    >
                        {item}
                    </Button>
                ),
            )}

            <Button
                variant="ghost"
                size="sm"
                disabled={page >= totalPages}
                onClick={() => onPageChange(page + 1)}
                aria-label="Página siguiente"
            >
                <span className="hidden sm:inline">Siguiente</span>
                <ChevronRight />
            </Button>
        </nav>
    );
}

export { Pagination };
