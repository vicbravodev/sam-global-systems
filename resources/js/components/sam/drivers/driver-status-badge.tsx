import { StatusBadge } from '@/components/sam/status-badge';
import { DRIVER_STATUS } from '@/lib/labels';
import type { DriverStatusValue } from '@/types/drivers';

export function DriverStatusBadge({
    status,
    className,
}: {
    status: DriverStatusValue;
    className?: string;
}) {
    return <StatusBadge dot {...DRIVER_STATUS[status]} className={className} />;
}
