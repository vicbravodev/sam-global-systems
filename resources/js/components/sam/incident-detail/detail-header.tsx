import { Link } from '@inertiajs/react';
import { ExternalLink, Maximize2, MapPin, Truck, User } from 'lucide-react';
import { SeverityBadge, StatusPill, ProviderTag } from '@/components/sam';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { IncidentDetail } from '@/types/sam';
import { useLiveSla } from '../inbox/use-live-sla';

// ---- BigSlaDisplay ----

interface BigSlaDisplayProps {
    incident: IncidentDetail;
}

function BigSlaDisplay({ incident }: BigSlaDisplayProps) {
    const live = useLiveSla(incident.slaSeconds);
    const consumed = incident.slaTotal > 0 ? 1 - live / incident.slaTotal : 1;
    const expired = live <= 0;
    const critical = expired || consumed >= 0.95;
    const high = !critical && consumed >= 0.8;
    const urgent = critical || high;

    const color = critical
        ? 'var(--color-severity-critical)'
        : high
          ? 'var(--color-severity-high)'
          : 'var(--color-fg-2)';

    const safeSeconds = Math.max(0, live);
    const m = Math.floor(safeSeconds / 60);
    const s = safeSeconds % 60;
    const txt = expired
        ? 'VENCIDO'
        : `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;

    return (
        <div
            className={cn(
                'flex min-w-[104px] flex-col items-start gap-0.5 rounded-md border bg-surface-2 px-3.5 py-2 tabular-nums transition-colors',
                urgent && !expired
                    ? consumed >= 0.95
                        ? 'animate-[sam-sla-pulse_1.6s_ease-in-out_infinite] border-severity-critical bg-severity-critical/20'
                        : 'animate-[sam-sla-pulse_1.6s_ease-in-out_infinite] border-severity-high bg-severity-high/15'
                    : 'border-border',
            )}
        >
            <span
                className="text-3xs font-semibold tracking-caps uppercase"
                style={{ color }}
            >
                SLA
            </span>
            <span
                className="font-mono text-xl font-semibold tabular-nums"
                style={{ color }}
            >
                {txt}
            </span>
            {urgent && !expired && (
                <span className="text-2xs" style={{ color }}>
                    vence pronto
                </span>
            )}
        </div>
    );
}

// ---- DetailHeader ----

interface DetailHeaderProps {
    incident: IncidentDetail;
    onClose: () => void;
    /** When set, shows the "Abrir detalle" CTA navigating to the full page. */
    detailHref?: string;
}

export function DetailHeader({
    incident,
    onClose,
    detailHref,
}: DetailHeaderProps) {
    return (
        <div
            className="grid shrink-0 items-start gap-3 border-b border-border bg-surface-1 p-[14px_18px]"
            style={{
                gridTemplateColumns: 'minmax(0,1fr) auto auto',
            }}
        >
            {/* Col 1: info */}
            <div className="min-w-0">
                <div className="mb-1 flex flex-wrap items-center gap-1.5">
                    <SeverityBadge level={incident.severity} />
                    <StatusPill state={incident.status} />
                    <ProviderTag name={incident.provider} />
                    <span className="font-mono text-2xs text-fg-3">
                        {incident.id}
                    </span>
                    <button
                        type="button"
                        className="cursor-pointer border-none bg-transparent p-0 text-fg-3 hover:text-fg-1"
                        aria-label="Ver en proveedor"
                    >
                        <ExternalLink size={12} />
                    </button>
                </div>

                <h2 className="mb-1.5 text-xl leading-tight font-semibold text-fg-1">
                    {incident.title}
                </h2>

                <div className="flex flex-wrap items-center gap-3 text-xs text-fg-2">
                    <span className="flex items-center gap-1">
                        <Truck
                            size={12}
                            className="text-fg-3"
                            strokeWidth={1.5}
                        />
                        {incident.asset}
                    </span>
                    <span className="flex items-center gap-1">
                        <User
                            size={12}
                            className="text-fg-3"
                            strokeWidth={1.5}
                        />
                        {incident.driver}
                    </span>
                    <span className="flex items-center gap-1">
                        <MapPin
                            size={12}
                            className="text-fg-3"
                            strokeWidth={1.5}
                        />
                        {incident.location}
                    </span>
                    <span className="text-fg-3">
                        Creado hace {incident.ageMin} min
                    </span>
                </div>
            </div>

            {/* Col 2: SLA big */}
            <BigSlaDisplay incident={incident} />

            {/* Col 3: actions */}
            <div className="flex flex-col gap-1.5">
                {detailHref && (
                    <Button size="sm" variant="default" asChild>
                        <Link href={detailHref}>
                            <Maximize2 size={12} />
                            Abrir detalle
                        </Link>
                    </Button>
                )}
                <Button size="sm" variant="outline">
                    <ExternalLink size={12} />
                    Ver en {incident.provider}
                </Button>
                <Button size="sm" variant="ghost" onClick={onClose}>
                    Cerrar detalle
                </Button>
            </div>
        </div>
    );
}
