import {
    Activity,
    AlertTriangle,
    CircleSlash,
    ShieldAlert,
    Unlink,
} from 'lucide-react';
import { PulseStat, PulseStrip } from '@/components/sam/pulse-strip';
import type { EventFilters, EventsSummary } from '@/types/events';

export function EventsPulse({
    summary,
    filters,
    onApply,
}: {
    summary: EventsSummary;
    filters: EventFilters;
    onApply: (next: EventFilters) => void;
}) {
    const toggleStatus = (value: string) => () =>
        onApply({
            ...filters,
            status: filters.status === value ? null : value,
        });

    return (
        <PulseStrip>
            <PulseStat
                label="Últimas 24 h"
                value={summary.last24h}
                icon={Activity}
                tone="info"
                live={summary.last24h > 0}
                hint="eventos normalizados"
            />
            <PulseStat
                label="Severos 24 h"
                value={summary.severe24h}
                icon={AlertTriangle}
                tone={summary.severe24h > 0 ? 'critical' : 'neutral'}
                hint="severidad alta o crítica"
            />
            <PulseStat
                label="Con incidente 24 h"
                value={summary.incidents24h}
                icon={ShieldAlert}
                tone={summary.incidents24h > 0 ? 'warn' : 'neutral'}
                hint="escalados a la bandeja"
            />
            <PulseStat
                label="Sin mapear"
                value={summary.unmapped}
                icon={Unlink}
                tone={summary.unmapped > 0 ? 'warn' : 'neutral'}
                hint="sin regla de normalización"
                onClick={toggleStatus('unmapped')}
                active={filters.status === 'unmapped'}
            />
            <PulseStat
                label="Fallidos"
                value={summary.failed}
                icon={CircleSlash}
                tone={summary.failed > 0 ? 'critical' : 'neutral'}
                hint="error en el pipeline"
                onClick={toggleStatus('failed')}
                active={filters.status === 'failed'}
            />
        </PulseStrip>
    );
}
