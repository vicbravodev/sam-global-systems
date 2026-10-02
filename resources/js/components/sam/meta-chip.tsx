import { cn } from '@/lib/utils';

/**
 * Tercera variante del sistema de chips (F3.3), junto a `SeverityBadge`
 * (severidad) y `StatusPill` (estado). Comparten geometría: `rounded-sm
 * px-1.5 py-1 text-3xs font-semibold tracking-label`. `MetaChip` es
 * el neutro para metadatos (origen, tipo, decisión informativa).
 */
export function MetaChip({
    children,
    className,
}: {
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-sm border border-border bg-surface-3 px-1.5 py-1 text-3xs font-semibold tracking-label whitespace-nowrap text-fg-3',
                className,
            )}
        >
            {children}
        </span>
    );
}
