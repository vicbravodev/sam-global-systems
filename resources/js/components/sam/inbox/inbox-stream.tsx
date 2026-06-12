import { MapPin, Truck, User } from 'lucide-react';
import { SeverityBadge, StatusPill, ProviderTag } from '@/components/sam';
import type { Severity } from '@/components/sam';
import { cn } from '@/lib/utils';
import type { MockIncident } from '@/types/sam';
import { useLiveSla } from './use-live-sla';

const SEVERITY_STRIPE: Record<Severity, string> = {
    critical: 'bg-severity-critical',
    high: 'bg-severity-high/80',
    medium: 'bg-severity-medium/60',
    low: 'bg-severity-low/60',
    info: 'bg-severity-info/60',
};

// ---- UserAvatar ----

function UserAvatar({
    initials,
    size = 20,
}: {
    initials: string;
    size?: number;
}) {
    return (
        <span
            className="inline-grid shrink-0 place-items-center rounded-full border border-border bg-surface-3 font-semibold text-fg-2"
            style={{
                width: size,
                height: size,
                fontSize: Math.max(9, size * 0.42),
            }}
        >
            {initials}
        </span>
    );
}

// ---- LiveSlaCell ----

function LiveSlaCell({ seconds, total }: { seconds: number; total: number }) {
    const live = useLiveSla(seconds);
    const consumed = total > 0 ? 1 - live / total : 1;
    const expired = live <= 0;
    const critical = expired || consumed >= 0.95;
    const high = !critical && consumed >= 0.8;

    const color = critical
        ? 'var(--color-severity-critical)'
        : high
          ? 'var(--color-severity-high)'
          : 'var(--color-fg-3)';

    const safeSeconds = Math.max(0, live);
    const m = Math.floor(safeSeconds / 60);
    const s = safeSeconds % 60;
    const label = expired
        ? 'VENCIDO'
        : `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;

    return (
        <span className="font-mono text-[11px] tabular-nums" style={{ color }}>
            {label}
        </span>
    );
}

// ---- StreamCard ----

interface StreamCardProps {
    incident: MockIncident;
    selected: boolean;
    onClick: () => void;
}

function StreamCard({ incident, selected, onClick }: StreamCardProps) {
    return (
        <div
            className={cn(
                'flex cursor-pointer overflow-hidden rounded-[6px] border border-border bg-surface-1',
                'transition-colors hover:bg-surface-2',
                selected ? 'border-primary/60 bg-primary/10' : '',
                incident.realtime
                    ? 'motion-safe:animate-[sam-flash_2.4s_ease-out_1]'
                    : '',
            )}
            onClick={onClick}
            role="button"
            tabIndex={0}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    onClick();
                }
            }}
        >
            {/* Color stripe */}
            <div
                className={cn(
                    'w-1 shrink-0',
                    SEVERITY_STRIPE[incident.severity],
                )}
            />

            {/* Content */}
            <div className="flex flex-1 flex-col gap-1.5 p-3">
                {/* Top row */}
                <div className="flex flex-wrap items-center gap-1.5">
                    <SeverityBadge level={incident.severity} />
                    <StatusPill state={incident.status} />
                    <ProviderTag name={incident.provider} />
                    <span className="font-mono text-[10px] text-fg-3">
                        {incident.id}
                    </span>
                    <span className="flex-1" />
                    <LiveSlaCell
                        seconds={incident.slaSeconds}
                        total={incident.slaTotal}
                    />
                </div>

                {/* Title */}
                <div className="text-[14px] font-medium text-fg-1">
                    {incident.title}
                </div>

                {/* Meta row */}
                <div className="flex flex-wrap items-center gap-3 text-[11px] text-fg-2">
                    <span className="flex items-center gap-1">
                        <Truck className="size-3 text-fg-3" strokeWidth={1.5} />
                        {incident.asset}
                    </span>
                    <span className="flex items-center gap-1">
                        <User className="size-3 text-fg-3" strokeWidth={1.5} />
                        {incident.driver}
                    </span>
                    <span className="flex items-center gap-1">
                        <MapPin
                            className="size-3 text-fg-3"
                            strokeWidth={1.5}
                        />
                        {incident.location}
                    </span>
                    <span className="flex-1" />
                    {incident.assignee ? (
                        <span className="flex items-center gap-1">
                            <UserAvatar
                                initials={incident.assignee.initials}
                                size={16}
                            />
                            <span className="text-[11px] text-fg-2">
                                {incident.assignee.name}
                            </span>
                        </span>
                    ) : (
                        <span className="text-[11px] text-fg-3 italic">
                            Sin asignar
                        </span>
                    )}
                </div>
            </div>
        </div>
    );
}

// ---- InboxStream ----

interface InboxStreamProps {
    rows: MockIncident[];
    selectedId: string | null;
    onSelect: (id: string) => void;
}

export function InboxStream({ rows, selectedId, onSelect }: InboxStreamProps) {
    return (
        <div className="flex min-h-0 flex-1 flex-col gap-2 overflow-auto p-3">
            {rows.map((incident) => (
                <StreamCard
                    key={incident.id}
                    incident={incident}
                    selected={selectedId === incident.id}
                    onClick={() => onSelect(incident.id)}
                />
            ))}
        </div>
    );
}
