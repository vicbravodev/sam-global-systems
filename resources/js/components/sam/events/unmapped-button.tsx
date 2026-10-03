import { Unlink } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/** "Sin mapear" toggle of the events list, with the unmapped count. */
export function UnmappedButton({
    active: unmappedActive,
    count: unmappedCount,
    onToggle,
}: {
    active: boolean;
    count: number;
    onToggle: () => void;
}) {
    return (
        <Button
            size="sm"
            variant={unmappedActive ? 'default' : 'outline'}
            onClick={onToggle}
        >
            <Unlink size={13} />
            Sin mapear
            <span
                className={cn(
                    'rounded-full px-1.5 font-mono text-3xs tabular-nums',
                    unmappedActive
                        ? 'bg-primary-foreground/20'
                        : unmappedCount > 0
                          ? 'bg-severity-high/15 text-severity-high'
                          : 'bg-surface-3 text-fg-3',
                )}
            >
                {unmappedCount}
            </span>
        </Button>
    );
}
