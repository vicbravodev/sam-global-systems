import { Hand, Loader2 } from 'lucide-react';
import {
    RelativeTime,
    SeverityBadge,
    StatusPill,
    TERMINAL_STATUSES,
} from '@/components/sam';
import type { IncidentStatus } from '@/components/sam';
import { cn } from '@/lib/utils';
import type { InboxDensity, MockAssignee, MockIncident } from '@/types/sam';
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

function LiveSlaCell({
    seconds,
    total,
    status,
}: {
    seconds: number;
    total: number;
    status: IncidentStatus;
}) {
    // Hook siempre se llama (reglas de hooks); el early-return terminal va
    // DESPUÉS, descartando el valor — un incidente terminal no tiene SLA
    // vivo y no debe mostrar countdown ni "VENCIDO".
    const live = useLiveSla(seconds);

    if (TERMINAL_STATUSES.includes(status)) {
        return <span className="font-mono text-xs text-fg-3">—</span>;
    }

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
        <span className="font-mono text-xs tabular-nums" style={{ color }}>
            {high && !critical && (
                <span aria-hidden="true" className="mr-0.5">
                    ▲
                </span>
            )}
            {label}
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

// ---- ClaimedBadge ----

/** Insignia de sólo lectura: quién tiene tomado el incidente, sin botón. */
function ClaimedBadge({ assignee }: { assignee: MockAssignee }) {
    return (
        <div
            className="flex min-w-0 items-center gap-1.5"
            title={`Tomado por ${assignee.name}`}
        >
            <UserAvatar initials={assignee.initials} size={20} />
            <span className="truncate text-3xs text-fg-3">{assignee.name}</span>
        </div>
    );
}

// ---- ClaimControl ----

/**
 * Toma humana desde la propia fila: el monitorista ve de un vistazo quién
 * tiene cada incidente sin abrirlo. Cuando lo tiene otro no hay botón — sólo
 * sus iniciales — porque soltarlo es potestad de quien lo tomó. Un
 * incidente terminal nunca admite toma: si alguien lo tuvo tomado se
 * conserva la insignia informativa, pero el botón Tomar/Soltar desaparece.
 */
function ClaimControl({
    incident,
    currentUserId,
    onClaimToggle,
    busy,
}: {
    incident: MockIncident;
    currentUserId: number | null;
    onClaimToggle: () => void;
    busy: boolean;
}) {
    const claimedByMe =
        incident.claimedBy !== null && incident.claimedBy.id === currentUserId;

    if (TERMINAL_STATUSES.includes(incident.status)) {
        return incident.claimedBy ? (
            <ClaimedBadge assignee={incident.claimedBy} />
        ) : null;
    }

    if (incident.claimedBy !== null && !claimedByMe) {
        return <ClaimedBadge assignee={incident.claimedBy} />;
    }

    return (
        <button
            type="button"
            disabled={busy}
            onClick={(e) => {
                e.stopPropagation();
                onClaimToggle();
            }}
            className={cn(
                'inline-flex cursor-pointer items-center gap-1 rounded-sm border px-1.5 py-1 text-3xs font-semibold tracking-label whitespace-nowrap',
                'disabled:cursor-not-allowed disabled:opacity-40',
                claimedByMe
                    ? 'border-primary/40 bg-primary/15 text-primary'
                    : 'border-border bg-surface-3 text-fg-2 hover:text-fg-1',
            )}
        >
            {busy ? (
                <Loader2 size={11} className="animate-spin" />
            ) : (
                <Hand size={11} />
            )}
            {claimedByMe ? 'Soltar' : 'Tomar'}
        </button>
    );
}

// ---- IncidentRow ----

interface IncidentRowProps {
    incident: MockIncident;
    selected: boolean;
    checked: boolean;
    density: InboxDensity;
    currentUserId: number | null;
    claimBusy: boolean;
    onClick: () => void;
    onToggle: () => void;
    onClaimToggle: () => void;
}

const DENSITY_H: Record<InboxDensity, string> = {
    compact: 'h-(--row-compact)',
    comfortable: 'h-(--row-comfortable)',
    relaxed: 'h-(--row-relaxed)',
};

export function IncidentRow({
    incident,
    selected,
    checked,
    density,
    currentUserId,
    claimBusy,
    onClick,
    onToggle,
    onClaimToggle,
}: IncidentRowProps) {
    const isCompact = density === 'compact';

    const rowClass = cn(
        'group cursor-pointer',
        selected
            ? 'bg-primary/10 [&>td:first-child]:shadow-[inset_3px_0_0_var(--color-primary)]'
            : 'hover:[&>td]:bg-surface-2',
        checked ? 'bg-primary/[8%]' : '',
        incident.realtime
            ? 'motion-safe:animate-[sam-flash_2.4s_ease-out_1]'
            : '',
    );

    const cellH = DENSITY_H[density];

    return (
        <tr
            className={cn(rowClass, 'outline-none focus-visible:bg-surface-2')}
            onClick={onClick}
            tabIndex={0}
            onKeyDown={(e) => {
                if (e.key === 'Enter') {
                    onClick();
                }
            }}
        >
            {/* Check */}
            <td
                className={cn(
                    'w-[34px] border-b border-border px-2.5 align-middle text-xs',
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
                    'w-24 border-b border-border px-2.5 align-middle text-xs',
                    cellH,
                )}
            >
                <SeverityBadge level={incident.severity} />
            </td>

            {/* Incident */}
            <td
                className={cn(
                    'flex-1 border-b border-border px-2.5 align-middle text-xs',
                    cellH,
                )}
            >
                <div className="flex min-w-0 flex-col justify-center gap-0.5">
                    <div className="flex min-w-0 items-center gap-1.5">
                        <span className="truncate font-medium text-fg-1">
                            {incident.title}
                        </span>
                        <span className="shrink-0 font-mono text-3xs text-fg-3">
                            {incident.id}
                        </span>
                        {incident.realtime && <LiveDot />}
                    </div>
                    {!isCompact && (
                        <div className="flex min-w-0 items-center gap-1.5 text-2xs text-fg-3">
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
                    'w-36 border-b border-border px-2.5 align-middle text-xs',
                    cellH,
                )}
            >
                <div className="flex min-w-0 flex-col">
                    <span className="truncate font-mono text-2xs text-fg-1">
                        {incident.asset.split(' · ')[0]}
                    </span>
                    {!isCompact && (
                        <span className="truncate text-3xs text-fg-3">
                            {incident.asset.split(' · ')[1] ?? ''}
                        </span>
                    )}
                </div>
            </td>

            {/* Driver */}
            <td
                className={cn(
                    'w-28 border-b border-border px-2.5 align-middle text-xs text-fg-2',
                    cellH,
                )}
            >
                <span className="block truncate">{incident.driver}</span>
            </td>

            {/* Assignee */}
            <td
                className={cn(
                    'w-40 border-b border-border px-2.5 align-middle text-xs',
                    cellH,
                )}
            >
                {incident.assignee ? (
                    <div className="flex min-w-0 items-center gap-1.5">
                        <UserAvatar
                            initials={incident.assignee.initials}
                            size={20}
                        />
                        <span className="truncate text-2xs text-fg-1">
                            {incident.assignee.name}
                        </span>
                    </div>
                ) : (
                    <span className="text-2xs text-fg-3 italic">
                        Sin asignar
                    </span>
                )}
            </td>

            {/* Claim */}
            <td
                className={cn(
                    'w-28 border-b border-border px-2.5 align-middle text-xs',
                    cellH,
                )}
            >
                <ClaimControl
                    incident={incident}
                    currentUserId={currentUserId}
                    onClaimToggle={onClaimToggle}
                    busy={claimBusy}
                />
            </td>

            {/* Status */}
            <td
                className={cn(
                    'w-28 border-b border-border px-2.5 align-middle text-xs',
                    cellH,
                )}
            >
                <StatusPill
                    state={incident.status}
                    label={incident.statusLabel}
                />
            </td>

            {/* SLA */}
            <td
                className={cn(
                    'w-24 border-b border-border px-2.5 align-middle text-xs',
                    cellH,
                )}
            >
                <LiveSlaCell
                    seconds={incident.slaSeconds}
                    total={incident.slaTotal}
                    status={incident.status}
                />
            </td>

            {/* Age */}
            <td
                className={cn(
                    'w-20 border-b border-border px-2.5 align-middle text-xs',
                    cellH,
                )}
            >
                <RelativeTime minutes={incident.ageMin} />
            </td>
        </tr>
    );
}

// ---- IncidentCard (D1: variante móvil de la fila) ----

interface IncidentCardProps {
    incident: MockIncident;
    selected: boolean;
    checked: boolean;
    currentUserId: number | null;
    claimBusy: boolean;
    onClick: () => void;
    onToggle: () => void;
    onClaimToggle: () => void;
}

export function IncidentCard({
    incident,
    selected,
    checked,
    currentUserId,
    claimBusy,
    onClick,
    onToggle,
    onClaimToggle,
}: IncidentCardProps) {
    return (
        <div
            role="button"
            tabIndex={0}
            onClick={onClick}
            onKeyDown={(e) => {
                if (e.key === 'Enter') {
                    onClick();
                }
            }}
            className={cn(
                'flex cursor-pointer flex-col gap-2 rounded-lg border border-border bg-surface-1 p-3 transition-colors outline-none hover:bg-surface-2 focus-visible:bg-surface-2',
                selected && 'ring-1 ring-primary',
                checked && 'bg-primary/[8%]',
            )}
        >
            <div className="flex items-start gap-2">
                <span
                    className={cn(
                        'mt-0.5 inline-grid shrink-0 cursor-pointer place-items-center rounded-sm border border-border-strong select-none',
                        checked
                            ? 'border-primary bg-primary'
                            : 'bg-transparent',
                    )}
                    style={{ width: 16, height: 16 }}
                    role="checkbox"
                    aria-checked={checked}
                    onClick={(e) => {
                        e.stopPropagation();
                        onToggle();
                    }}
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
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <div className="flex min-w-0 items-center gap-1.5">
                        <span className="truncate font-medium text-fg-1">
                            {incident.title}
                        </span>
                        {incident.realtime && <LiveDot />}
                    </div>
                    <span className="font-mono text-3xs text-fg-3">
                        {incident.id} · {incident.provider}
                    </span>
                </div>
                <SeverityBadge level={incident.severity} />
            </div>

            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-2xs text-fg-3">
                <StatusPill
                    state={incident.status}
                    label={incident.statusLabel}
                />
                <span className="font-mono">
                    {incident.asset.split(' · ')[0]}
                </span>
                {incident.driver && <span>{incident.driver}</span>}
                <span className="ml-auto flex items-center gap-2">
                    <LiveSlaCell
                        seconds={incident.slaSeconds}
                        total={incident.slaTotal}
                        status={incident.status}
                    />
                    <RelativeTime minutes={incident.ageMin} />
                </span>
            </div>

            <div className="flex items-center justify-between gap-2 text-2xs text-fg-3">
                {incident.assignee ? (
                    <span className="flex min-w-0 items-center gap-1.5">
                        <UserAvatar
                            initials={incident.assignee.initials}
                            size={18}
                        />
                        <span className="truncate">
                            {incident.assignee.name}
                        </span>
                    </span>
                ) : (
                    <span className="italic">Sin asignar</span>
                )}
                <ClaimControl
                    incident={incident}
                    currentUserId={currentUserId}
                    onClaimToggle={onClaimToggle}
                    busy={claimBusy}
                />
            </div>
        </div>
    );
}
