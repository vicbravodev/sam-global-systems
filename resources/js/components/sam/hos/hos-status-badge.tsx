import { StatusBadge } from '@/components/sam/status-badge';
import {
    HOS_APP_DISCONNECTED,
    HOS_DUTY_STATUS,
    HOS_UNKNOWN_STATUS,
} from './copy';

export interface HosStatusBadgeProps {
    dutyStatus: string | null;
    appDisconnected: boolean;
}

/** Estado del chofer en Samsara; la app desconectada gana. */
export function HosStatusBadge({
    dutyStatus,
    appDisconnected,
}: HosStatusBadgeProps) {
    const status = appDisconnected
        ? HOS_APP_DISCONNECTED
        : ((dutyStatus === null ? undefined : HOS_DUTY_STATUS[dutyStatus]) ??
          HOS_UNKNOWN_STATUS);

    return <StatusBadge size="sm" dot {...status} />;
}
