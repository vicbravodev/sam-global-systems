import { isMoving } from '@/components/sam/map/markers';
import { cn } from '@/lib/utils';
import type { AssetMarker } from '@/types/assets';
import { StatusDot } from './status-dot';

/**
 * One unit in the roster. Its own component so the React Compiler memoizes
 * it per row: a feed tick re-renders only the units that moved. The "last
 * seen" label comes from the parent (recomputed on every tick), so a parked
 * unit still re-renders when its minute-level label changes.
 */
export function RosterRow({
    asset,
    selected,
    statusLabel,
    seenLabel,
    onPick,
}: {
    asset: AssetMarker;
    selected: boolean;
    statusLabel: string;
    seenLabel: string;
    onPick: (id: number) => void;
}) {
    const moving = isMoving(asset);

    return (
        <li>
            <button
                type="button"
                onClick={() => onPick(asset.id)}
                aria-current={selected ? 'true' : undefined}
                className={cn(
                    'flex h-(--row-relaxed) w-full cursor-pointer items-center gap-2.5 px-3 text-left transition-colors duration-(--motion-fast) outline-none focus-visible:bg-surface-2',
                    selected ? 'bg-surface-2' : 'hover:bg-surface-2/70',
                )}
            >
                <StatusDot status={asset.status} />
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-xs font-medium text-fg-1">
                        {asset.code ? `${asset.code} · ` : ''}
                        {asset.name}
                    </span>
                    <span className="block truncate text-2xs text-fg-3">
                        {asset.driver ?? statusLabel}
                    </span>
                </span>
                <span className="flex shrink-0 flex-col items-end gap-0.5">
                    <span
                        className={cn(
                            'text-2xs tabular-nums',
                            moving ? 'font-medium text-fg-1' : 'text-fg-3',
                        )}
                    >
                        {moving && asset.speed !== null
                            ? `${Math.round(asset.speed)} km/h`
                            : moving
                              ? 'En ruta'
                              : 'Detenido'}
                    </span>
                    <span className="text-3xs text-fg-3">{seenLabel}</span>
                </span>
            </button>
        </li>
    );
}
