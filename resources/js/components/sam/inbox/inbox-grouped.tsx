import { SeverityBadge } from '@/components/sam';
import type { Severity } from '@/components/sam';
import { cn } from '@/lib/utils';
import type { InboxDensity, MockIncident } from '@/types/sam';
import { IncidentRow } from './incident-row';
import { useLiveSla } from './use-live-sla';

// ---- MinSlaCell ----

function MinSlaCell({ seconds, total }: { seconds: number; total: number }) {
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

const SEVERITY_ORDER: Severity[] = [
    'critical',
    'high',
    'medium',
    'low',
    'info',
];

const SEVERITY_BORDER: Record<Severity, string> = {
    critical: 'border-l-severity-critical',
    high: 'border-l-severity-high',
    medium: 'border-l-severity-medium',
    low: 'border-l-severity-low',
    info: 'border-l-severity-info',
};

interface InboxGroupedProps {
    rows: MockIncident[];
    selectedId: string | null;
    selectedSet: Set<string>;
    density: InboxDensity;
    onSelect: (id: string) => void;
    onToggle: (id: string) => void;
}

export function InboxGrouped({
    rows,
    selectedId,
    selectedSet,
    density,
    onSelect,
    onToggle,
}: InboxGroupedProps) {
    const groups = SEVERITY_ORDER.map((sev) => ({
        severity: sev,
        items: rows.filter((r) => r.severity === sev),
    })).filter((g) => g.items.length > 0);

    return (
        <div className="min-h-0 flex-1 overflow-auto">
            {groups.map(({ severity, items }) => {
                const minSla = items.reduce(
                    (min, r) => (r.slaSeconds < min.slaSeconds ? r : min),
                    items[0],
                );

                return (
                    <div key={severity}>
                        {/* Group header */}
                        <div
                            className={cn(
                                'sticky top-0 z-[3] flex items-center gap-2.5 border-b border-l-[3px] border-border bg-surface-3 px-5 py-2.5',
                                SEVERITY_BORDER[severity],
                            )}
                        >
                            <SeverityBadge level={severity} />
                            <span className="text-[12px] font-semibold text-fg-2">
                                {items.length}{' '}
                                {items.length === 1
                                    ? 'incidente'
                                    : 'incidentes'}
                            </span>
                            <span className="ml-auto flex items-center gap-1 text-[11px] text-fg-3">
                                SLA mín:
                                <MinSlaCell
                                    seconds={minSla.slaSeconds}
                                    total={minSla.slaTotal}
                                />
                            </span>
                        </div>

                        {/* Sub-table */}
                        <table className="w-full border-collapse">
                            <tbody>
                                {items.map((incident) => (
                                    <IncidentRow
                                        key={incident.id}
                                        incident={incident}
                                        selected={selectedId === incident.id}
                                        checked={selectedSet.has(incident.id)}
                                        density={density}
                                        onClick={() => onSelect(incident.id)}
                                        onToggle={() => onToggle(incident.id)}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                );
            })}
        </div>
    );
}
