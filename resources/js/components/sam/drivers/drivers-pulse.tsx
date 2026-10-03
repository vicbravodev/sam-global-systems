import {
    Clock,
    Moon,
    ShieldAlert,
    Truck,
    UserCheck,
    UserX,
    Users,
} from 'lucide-react';
import { PulseStat, PulseStrip } from '@/components/sam/pulse-strip';
import type { DriversSummary } from '@/types/drivers';

export interface DriversPulseProps {
    summary: DriversSummary;
    status: string | null;
    onStatus: (value: string | null) => void;
}

export function DriversPulse({ summary, status, onStatus }: DriversPulseProps) {
    const toggle = (value: string) => () =>
        onStatus(status === value ? null : value);
    const attention =
        summary.statuses.under_review + summary.statuses.suspended;

    return (
        <PulseStrip>
            <PulseStat
                label="Roster"
                value={summary.total}
                icon={Users}
                hint="conductores registrados"
                onClick={() => onStatus(null)}
                active={status === null}
            />
            <PulseStat
                label="Activos"
                value={summary.statuses.active}
                icon={UserCheck}
                tone="ok"
                hint="en servicio"
                onClick={toggle('active')}
                active={status === 'active'}
            />
            <PulseStat
                label="Fuera de turno"
                value={summary.statuses.off_duty}
                icon={Moon}
                hint="descansando"
                onClick={toggle('off_duty')}
                active={status === 'off_duty'}
            />
            <PulseStat
                label="Atención"
                value={attention}
                icon={UserX}
                tone={attention > 0 ? 'warn' : 'neutral'}
                hint={`${summary.statuses.under_review} en revisión · ${summary.statuses.suspended} suspendidos`}
                onClick={toggle('under_review')}
                active={status === 'under_review'}
            />
            <PulseStat
                label="Riesgo alto"
                value={summary.highRisk}
                icon={ShieldAlert}
                tone={summary.highRisk > 0 ? 'critical' : 'neutral'}
                hint="perfil alto o crítico"
            />
            <PulseStat
                label="Sin unidad"
                value={summary.unassigned}
                icon={Truck}
                tone={summary.unassigned > 0 ? 'warn' : 'neutral'}
                hint="sin vehículo asignado"
            />
            <PulseStat
                label="Vistos 24 h"
                value={summary.seenToday}
                icon={Clock}
                tone="info"
                live={summary.seenToday > 0}
                hint="con señal del proveedor"
            />
        </PulseStrip>
    );
}
