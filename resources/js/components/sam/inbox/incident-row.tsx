import { SeverityBadge, StatusPill } from '@/components/sam';
import { cn } from '@/lib/utils';
import type { InboxDensity, MockIncident } from '@/types/sam';
import { useLiveSla } from './use-live-sla';

// ---- UserAvatar ----

function UserAvatar({
    initials,
    size = 24,
    isPrimary = false,
}: {
    initials: string;
    size?: number;
    isPrimary?: boolean;
}) {
    return (
        <span
            className={cn(
                'inline-grid shrink-0 place-items-center rounded-full border border-border font-semibold',
                isPrimary
                    ? 'bg-primary text-primary-foreground'
                    : 'bg-surface-3 text-fg-2',
            )}
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
          : 'var(--color-fg-2)';

    const safeSeconds = Math.max(0, live);
    const m = Math.floor(safeSeconds / 60);
    const s = safeSeconds % 60;
    const label = expired
        ? 'VENCIDO'
        : `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;

    return (
        <span className="font-mono text-[12px] tabular-nums" style={{ color }}>
            {high && !critical && (
                <span aria-hidden="true" className="mr-0.5">
                    ▲
                </span>
            )}
            {label}
        </span>
    );
}

// ---- RelativeTime ----

function RelativeTimeCell({ min }: { min: number }) {
    const text =
        min < 1
            ? 'ahora'
            : min < 60
              ? `${min} min`
              : min < 1440
                ? `${Math.floor(min / 60)} h`
                : `${Math.floor(min / 1440)} d`;

    return (
        <span className="font-mono text-[11px] text-fg-3 tabular-nums">
            {text}
        </span>
    );
}

// ---- LiveDot ----

function LiveDot() {
    return (
        <span className="relative ml-1 inline-flex size-1.5 shrink-0">
            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-severity-critical opacity-60" />
            <span className="relative inline-flex size-1.5 rounded-full bg-severity-critical" />
        </span>
    );
}

// ---- IncidentRow ----

interface IncidentRowProps {
    incident: MockIncident;
    selected: boolean;
    checked: boolean;
    density: InboxDensity;
    onClick: () => void;
    onToggle: () => void;
}

const DENSITY_H: Record<InboxDensity, string> = {
    compact: 'h-9',
    comfortable: 'h-[46px]',
    relaxed: 'h-16',
};

export function IncidentRow({
    incident,
    selected,
    checked,
    density,
    onClick,
    onToggle,
}: IncidentRowProps) {
    const isCompact = density === 'compact';

    const rowClass = cn(
        'group cursor-pointer',
        selected
            ? 'bg-primary/10 [&>td:first-child]:shadow-[inset_3px_0_0_var(--color-primary)]'
            : 'hover:[&>td]:bg-surface-2',
        checked ? 'bg-primary/[8%]' : '',
        incident.realtime ? 'animate-[sam-flash_2.4s_ease-out_1]' : '',
    );

    const cellH = DENSITY_H[density];

    return (
        <tr className={rowClass} onClick={onClick}>
            {/* Check */}
            <td
                className={cn(
                    'w-[34px] border-b border-border px-2.5 align-middle text-[12px]',
                    cellH,
                )}
                onClick={(e) => {
                    e.stopPropagation();
                    onToggle();
                }}
            >
                <span
                    className={cn(
                        'inline-grid place-items-center rounded-sm border border-border-strong',
                        'cursor-pointer select-none',
                        checked
                            ? 'border-primary bg-primary'
                            : 'bg-transparent',
                    )}
                    style={{ width: 14, height: 14 }}
                    role="checkbox"
                    aria-checked={checked}
                >
                    {checked && (
                        <svg
                            width="9"
                            height="7"
                            viewBox="0 0 9 7"
                            fill="none"
                            aria-hidden="true"
                        >
                            <path
                                d="M1 3.5L3.5 6L8 1"
                                stroke="white"
                                strokeWidth="1.5"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                            />
                        </svg>
                    )}
                </span>
            </td>

            {/* Severity */}
            <td
                className={cn(
                    'w-24 border-b border-border px-2.5 align-middle text-[12px]',
                    cellH,
                )}
            >
                <SeverityBadge level={incident.severity} />
            </td>

            {/* Incident */}
            <td
                className={cn(
                    'flex-1 border-b border-border px-2.5 align-middle text-[12px]',
                    cellH,
                )}
            >
                <div className="flex min-w-0 flex-col justify-center gap-0.5">
                    <div className="flex min-w-0 items-center gap-1.5">
                        <span className="truncate font-medium text-fg-1">
                            {incident.title}
                        </span>
                        <span className="shrink-0 font-mono text-[10px] text-fg-3">
                            {incident.id}
                        </span>
                        {incident.realtime && <LiveDot />}
                    </div>
                    {!isCompact && (
                        <div className="flex min-w-0 items-center gap-1.5 text-[11px] text-fg-3">
                            <span className="shrink-0">
                                {incident.provider}
                            </span>
                            <span>·</span>
                            <span className="truncate">
                                {incident.location}
                            </span>
                        </div>
                    )}
                </div>
            </td>

            {/* Asset */}
            <td
                className={cn(
                    'w-36 border-b border-border px-2.5 align-middle text-[12px]',
                    cellH,
                )}
            >
                <div className="flex min-w-0 flex-col">
                    <span className="truncate font-mono text-[11px] text-fg-1">
                        {incident.asset.split(' · ')[0]}
                    </span>
                    {!isCompact && (
                        <span className="truncate text-[10px] text-fg-3">
                            {incident.asset.split(' · ')[1] ?? ''}
                        </span>
                    )}
                </div>
            </td>

            {/* Driver */}
            <td
                className={cn(
                    'w-28 border-b border-border px-2.5 align-middle text-[12px] text-fg-2',
                    cellH,
                )}
            >
                <span className="block truncate">{incident.driver}</span>
            </td>

            {/* Assignee */}
            <td
                className={cn(
                    'w-40 border-b border-border px-2.5 align-middle text-[12px]',
                    cellH,
                )}
            >
                {incident.assignee ? (
                    <div className="flex min-w-0 items-center gap-1.5">
                        <UserAvatar
                            initials={incident.assignee.initials}
                            size={20}
                        />
                        <span className="truncate text-[11px] text-fg-1">
                            {incident.assignee.name}
                        </span>
                    </div>
                ) : (
                    <span className="text-[11px] text-fg-3 italic">
                        Sin asignar
                    </span>
                )}
            </td>

            {/* Status */}
            <td
                className={cn(
                    'w-28 border-b border-border px-2.5 align-middle text-[12px]',
                    cellH,
                )}
            >
                <StatusPill state={incident.status} />
            </td>

            {/* SLA */}
            <td
                className={cn(
                    'w-24 border-b border-border px-2.5 align-middle text-[12px]',
                    cellH,
                )}
            >
                <LiveSlaCell
                    seconds={incident.slaSeconds}
                    total={incident.slaTotal}
                />
            </td>

            {/* Age */}
            <td
                className={cn(
                    'w-20 border-b border-border px-2.5 align-middle text-[12px]',
                    cellH,
                )}
            >
                <RelativeTimeCell min={incident.ageMin} />
            </td>
        </tr>
    );
}
