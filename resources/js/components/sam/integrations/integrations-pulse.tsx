import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    CircleDashed,
    Plug,
    Truck,
    Users,
} from 'lucide-react';
import type { Dispatch, SetStateAction } from 'react';
import { PulseStat, PulseStrip } from '@/components/sam/pulse-strip';
import { formatNumber } from '@/lib/format';
import type { IntegrationsSummary, TenantIntegrationStatus } from '@/types/sam';

export interface IntegrationsPulseProps {
    summary: IntegrationsSummary;
    filter: TenantIntegrationStatus | null;
    setFilter: Dispatch<SetStateAction<TenantIntegrationStatus | null>>;
}

/** Connection health and fleet figures; status tiles toggle the filter. */
export function IntegrationsPulse({
    summary,
    filter,
    setFilter,
}: IntegrationsPulseProps) {
    const toggle = (status: TenantIntegrationStatus) => () =>
        setFilter((current) => (current === status ? null : status));

    return (
        <PulseStrip>
            <PulseStat
                label="Conexiones"
                value={summary.total}
                icon={Plug}
                hint="con tus proveedores"
                onClick={() => setFilter(null)}
                active={filter === null}
            />
            <PulseStat
                label="Funcionando"
                value={summary.working}
                icon={CheckCircle2}
                tone={summary.working > 0 ? 'ok' : 'neutral'}
                hint="reciben datos"
                onClick={toggle('active')}
                active={filter === 'active'}
            />
            <PulseStat
                label="Atención"
                value={summary.attention}
                icon={AlertTriangle}
                tone={summary.attention > 0 ? 'critical' : 'neutral'}
                hint={
                    summary.attention > 0
                        ? 'sin datos hasta resolverlo'
                        : 'todo en orden'
                }
                onClick={toggle('error')}
                active={filter === 'error'}
            />
            <PulseStat
                label="Pendientes"
                value={summary.pending}
                icon={CircleDashed}
                tone={summary.pending > 0 ? 'warn' : 'neutral'}
                hint="falta terminar de configurar"
                onClick={toggle('pending')}
                active={filter === 'pending'}
            />
            <PulseStat
                label="Eventos 24 h"
                value={formatNumber(summary.events24h)}
                icon={Activity}
                tone="info"
                live={summary.events24h > 0}
                hint="recibidos de tus proveedores"
            />
            <PulseStat
                label="Unidades"
                value={formatNumber(summary.assets)}
                icon={Truck}
                hint={`${formatNumber(summary.monitored)} monitoreadas`}
            />
            <PulseStat
                label="Conductores"
                value={formatNumber(summary.drivers)}
                icon={Users}
                hint="traídos del proveedor"
            />
        </PulseStrip>
    );
}
