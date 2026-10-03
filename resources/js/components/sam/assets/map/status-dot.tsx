import { statusColor } from '@/components/sam/map/markers';
import { cn } from '@/lib/utils';
import type { AssetStatusValue } from '@/types/assets';

export function StatusDot({
    status,
    className,
}: {
    status: AssetStatusValue;
    className?: string;
}) {
    const muted = status === 'offline' || status === 'inactive';

    return (
        <span
            aria-hidden="true"
            className={cn('size-2 shrink-0 rounded-full', className)}
            style={
                muted
                    ? { boxShadow: `inset 0 0 0 1.5px ${statusColor(status)}` }
                    : { backgroundColor: statusColor(status) }
            }
        />
    );
}
