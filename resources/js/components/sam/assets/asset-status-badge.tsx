import { StatusBadge } from '@/components/sam/status-badge';
import { ASSET_STATUS } from '@/lib/labels';
import type { AssetStatusValue } from '@/types/assets';

export function AssetStatusBadge({
    status,
    className,
}: {
    status: AssetStatusValue;
    className?: string;
}) {
    return <StatusBadge dot {...ASSET_STATUS[status]} className={className} />;
}
