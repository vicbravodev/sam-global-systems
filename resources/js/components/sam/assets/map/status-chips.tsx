import { cn } from '@/lib/utils';
import type { AssetStatusValue } from '@/types/assets';
import { StatusDot } from './status-dot';

export interface StatusChipsProps {
    statuses: AssetStatusValue[];
    hidden: ReadonlySet<AssetStatusValue>;
    counts: ReadonlyMap<AssetStatusValue, number>;
    statusLabels: Record<string, string>;
    onToggle: (status: AssetStatusValue) => void;
}

/** Status filter of the live map: one toggle chip per status on screen. */
export function StatusChips({
    statuses,
    hidden,
    counts,
    statusLabels,
    onToggle,
}: StatusChipsProps) {
    return (
        <div className="flex flex-wrap gap-1.5">
            {statuses.map((status) => {
                const off = hidden.has(status);

                return (
                    <button
                        key={status}
                        type="button"
                        onClick={() => onToggle(status)}
                        aria-pressed={!off}
                        className={cn(
                            'inline-flex h-7 cursor-pointer items-center gap-1.5 rounded-full border px-2.5 text-2xs font-medium transition-colors duration-(--motion-fast) focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            off
                                ? 'border-dashed border-border bg-transparent text-fg-3 hover:text-fg-2'
                                : 'border-border bg-surface-1 text-fg-1 hover:bg-surface-2',
                        )}
                    >
                        <StatusDot
                            status={status}
                            className={cn(off && 'opacity-40')}
                        />
                        {statusLabels[status] ?? status}
                        <span className="text-fg-3 tabular-nums">
                            {counts.get(status)}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
