import { Bell, CircleSlash, Send, Siren } from 'lucide-react';
import { PulseStat, PulseStrip } from '@/components/sam/pulse-strip';
import type {
    NotificationFilters,
    NotificationsSummary,
} from '@/types/notifications';

export interface NotificationsPulseProps {
    summary: NotificationsSummary;
    filters: NotificationFilters;
    onApply: (next: NotificationFilters) => void;
}

export function NotificationsPulse({
    summary,
    filters,
    onApply,
}: NotificationsPulseProps) {
    return (
        <PulseStrip>
            <PulseStat
                label="Sin leer"
                value={summary.unread}
                icon={Bell}
                tone={summary.unread > 0 ? 'primary' : 'neutral'}
                hint="para ti, en este equipo"
                onClick={() => onApply({ ...filters, unread: !filters.unread })}
                active={filters.unread}
            />
            <PulseStat
                label="Enviadas 24 h"
                value={summary.sent24h}
                icon={Send}
                tone="ok"
                hint="salieron por algún canal"
            />
            <PulseStat
                label="No entregadas 24 h"
                value={summary.undelivered24h}
                icon={CircleSlash}
                tone={summary.undelivered24h > 0 ? 'critical' : 'neutral'}
                hint="con entregas fallidas, fallidas o canceladas"
                onClick={() =>
                    onApply({ ...filters, failures: !filters.failures })
                }
                active={filters.failures}
            />
            <PulseStat
                label="Críticas 24 h"
                value={summary.critical24h}
                icon={Siren}
                tone={summary.critical24h > 0 ? 'critical' : 'neutral'}
                hint="prioridad crítica"
                onClick={() =>
                    onApply({
                        ...filters,
                        priority:
                            filters.priority === 'critical' ? null : 'critical',
                    })
                }
                active={filters.priority === 'critical'}
            />
        </PulseStrip>
    );
}
