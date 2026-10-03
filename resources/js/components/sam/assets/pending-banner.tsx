import { Eye, EyeOff } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { MonitoringSummary } from '@/types/assets';

export function PendingBanner({
    monitoring,
    teamSlug,
    onShowPending,
    onMonitorAll,
    busy,
}: {
    monitoring: MonitoringSummary;
    teamSlug: string | null;
    onShowPending: () => void;
    onMonitorAll: () => void;
    busy: boolean;
}) {
    if (monitoring.pending === 0) {
        return null;
    }

    const capText =
        monitoring.cap === null
            ? `Vigilas ${monitoring.monitored} unidades, sin tope contratado.`
            : `Vigilas ${monitoring.monitored} de ${monitoring.cap} contratadas.` +
              (monitoring.monitored + monitoring.pending > monitoring.cap
                  ? ' Encender más allá del tope se cobra como extra por cada día encendida.'
                  : ' Aún tienes cupo dentro de lo contratado.');

    return (
        <div className="flex shrink-0 flex-col gap-2 border-b border-severity-medium/40 bg-severity-medium/10 px-5 py-2.5 text-xs text-fg-2 sm:flex-row sm:items-center sm:justify-between">
            <p>
                <span className="font-medium text-fg-1">
                    {monitoring.pending}{' '}
                    {monitoring.pending === 1
                        ? 'unidad nueva sin vigilar'
                        : 'unidades nuevas sin vigilar'}
                    .
                </span>{' '}
                SAM no las vigila ni las cobra hasta que las enciendas.{' '}
                {capText}
            </p>
            <div className="flex shrink-0 items-center gap-2">
                <Button variant="outline" size="sm" onClick={onShowPending}>
                    <EyeOff size={13} />
                    Ver pendientes
                </Button>
                {teamSlug && (
                    <Button size="sm" onClick={onMonitorAll} disabled={busy}>
                        <Eye size={13} />
                        Vigilar todas ({monitoring.pending})
                    </Button>
                )}
            </div>
        </div>
    );
}
