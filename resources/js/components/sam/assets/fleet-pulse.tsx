import {
    Camera,
    Eye,
    EyeOff,
    Navigation,
    Radio,
    RadioTower,
    Siren,
    Truck,
    Wrench,
} from 'lucide-react';
import { PulseStat, PulseStrip } from '@/components/sam/pulse-strip';
import type { AssetsSummary, MonitoringSummary } from '@/types/assets';

// Tiles of `FleetPulse`, for its skeleton while the deferred figures load.
export const FLEET_PULSE_LABELS = [
    'Flota',
    'Vigiladas',
    'Sin vigilar',
    'Reportando',
    'En ruta',
    'Sin señal',
    'Alerta o crítico',
    'Mantenimiento',
    'Con cámara',
] as const;

export function FleetPulse({
    summary,
    monitoring,
    status,
    monitoringFilter,
    onStatus,
    onMonitoring,
}: {
    summary: AssetsSummary;
    monitoring: MonitoringSummary | null;
    status: string | null;
    monitoringFilter: string | null;
    onStatus: (value: string | null) => void;
    onMonitoring: (value: string | null) => void;
}) {
    const toggle = (value: string) => () =>
        onStatus(status === value ? null : value);
    const toggleMonitoring = (value: string) => () =>
        onMonitoring(monitoringFilter === value ? null : value);

    return (
        <PulseStrip>
            <PulseStat
                label="Flota"
                value={summary.total}
                icon={Truck}
                hint="unidades registradas"
                onClick={() => onStatus(null)}
                active={status === null}
            />
            {monitoring && (
                <PulseStat
                    label="Vigiladas"
                    value={monitoring.monitored}
                    icon={Eye}
                    tone={monitoring.overCap ? 'warn' : 'ok'}
                    hint={
                        monitoring.cap === null
                            ? 'sin tope contratado'
                            : monitoring.overCap
                              ? `${monitoring.monitored - monitoring.cap} por encima del tope de ${monitoring.cap} (se cobra extra)`
                              : `de ${monitoring.cap} contratadas`
                    }
                    onClick={toggleMonitoring('monitored')}
                    active={monitoringFilter === 'monitored'}
                />
            )}
            {monitoring && (
                <PulseStat
                    label="Sin vigilar"
                    value={monitoring.pending}
                    icon={EyeOff}
                    tone={monitoring.pending > 0 ? 'warn' : 'neutral'}
                    hint="nuevas, tú decides si se vigilan"
                    onClick={toggleMonitoring('pending')}
                    active={monitoringFilter === 'pending'}
                />
            )}
            <PulseStat
                label="Reportando"
                value={summary.reporting}
                icon={RadioTower}
                tone="ok"
                live={summary.reporting > 0}
                hint="señal en los últimos 15 min"
            />
            <PulseStat
                label="En ruta"
                value={summary.moving}
                icon={Navigation}
                tone="info"
                live={summary.moving > 0}
                hint="en movimiento ahora"
            />
            <PulseStat
                label="Sin señal"
                value={summary.silent}
                icon={Radio}
                tone={summary.silent > 0 ? 'warn' : 'neutral'}
                hint="más de 24 h calladas"
            />
            <PulseStat
                label="Alerta o crítico"
                value={summary.alerting}
                icon={Siren}
                tone={summary.alerting > 0 ? 'critical' : 'neutral'}
                hint={`${summary.statuses.alert} alerta · ${summary.statuses.critical} crítico`}
                onClick={toggle('alert')}
                active={status === 'alert'}
            />
            <PulseStat
                label="Mantenimiento"
                value={summary.maintenance}
                icon={Wrench}
                tone={summary.maintenance > 0 ? 'warn' : 'neutral'}
                hint="fuera de operación"
                onClick={toggle('maintenance')}
                active={status === 'maintenance'}
            />
            <PulseStat
                label="Con cámara"
                value={summary.withCamera}
                icon={Camera}
                hint="dashcam vinculada"
            />
        </PulseStrip>
    );
}
