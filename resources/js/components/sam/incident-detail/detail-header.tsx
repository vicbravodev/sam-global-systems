import { Link } from '@inertiajs/react';
import { Maximize2, MapPin, Truck, User } from 'lucide-react';
import {
    RelativeTime,
    SeverityBadge,
    StatusPill,
    ProviderTag,
    TERMINAL_STATUSES,
} from '@/components/sam';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { IncidentDetail } from '@/types/sam';
import { useLiveSla } from '../inbox/use-live-sla';

// ---- BigSlaDisplay ----

interface BigSlaDisplayProps {
    incident: IncidentDetail;
}

function BigSlaDisplay({ incident }: BigSlaDisplayProps) {
    // Hook siempre se llama (reglas de hooks); el early-return terminal va
    // DESPUÉS, descartando el valor.
    const live = useLiveSla(incident.slaSeconds);

    if (TERMINAL_STATUSES.includes(incident.status)) {
        // Terminal: sin SLA vivo. El StatusPill del header ya comunica el
        // estado — no duplicamos con un bloque "VENCIDO" en rojo.
        return null;
    }

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
                        ? 'border-severity-critical bg-severity-critical/20 motion-safe:animate-[sam-sla-pulse_1.6s_ease-in-out_infinite]'
                        : 'border-severity-high bg-severity-high/15 motion-safe:animate-[sam-sla-pulse_1.6s_ease-in-out_infinite]'
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
        <div className="grid shrink-0 grid-cols-[minmax(0,1fr)_auto] items-start gap-3 border-b border-border bg-surface-1 p-[14px_18px] @max-4xl:grid-cols-1">
            {/* Col 1: info */}
            <div className="min-w-0">
                <div className="mb-1 flex flex-wrap items-center gap-1.5">
                    <SeverityBadge level={incident.severity} />
                    <StatusPill
                        state={incident.status}
                        label={incident.statusLabel}
                    />
                    <ProviderTag name={incident.provider} />
                    <span className="font-mono text-2xs text-fg-3">
                        {incident.id}
                    </span>
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
                        Creado{' '}
                        <RelativeTime
                            minutes={incident.ageMin}
                            className="text-xs text-fg-3"
                        />
                    </span>
                </div>
            </div>

            {/* Col 2: SLA + actions — grouped so that in the 1-column
                (narrow container) layout they land in a single wrapping row
                below the info block, instead of each stacking on its own
                row. */}
            <div className="flex flex-wrap items-start gap-3">
                <BigSlaDisplay incident={incident} />

                <div className="flex flex-col gap-1.5">
                    {detailHref && (
                        <Button size="sm" variant="default" asChild>
                            <Link href={detailHref}>
                                <Maximize2 size={12} />
                                Abrir detalle
                            </Link>
                        </Button>
                    )}
                    <Button size="sm" variant="ghost" onClick={onClose}>
                        Cerrar detalle
                    </Button>
                </div>
            </div>
        </div>
    );
}
