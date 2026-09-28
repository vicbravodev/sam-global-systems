import { X } from 'lucide-react';

import { cn } from '@/lib/utils';

export function ClearFiltersButton({
    onClick,
    className,
}: {
    onClick: () => void;
    className?: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'flex items-center gap-1 rounded-sm border border-dashed border-border px-2.5 py-1.5 text-2xs whitespace-nowrap text-fg-3 transition-colors hover:border-border-strong hover:text-fg-1',
                className,
            )}
        >
            <X size={11} />
            Limpiar
        </button>
    );
}
