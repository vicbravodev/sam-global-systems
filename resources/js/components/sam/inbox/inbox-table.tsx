import type { InboxDensity, MockIncident } from '@/types/sam';
import { IncidentRow } from './incident-row';

interface InboxTableProps {
    rows: MockIncident[];
    selectedId: string | null;
    selectedSet: Set<string>;
    density: InboxDensity;
    onSelect: (id: string) => void;
    onToggle: (id: string) => void;
    onSelectAll: () => void;
    allChecked: boolean;
}

export function InboxTable({
    rows,
    selectedId,
    selectedSet,
    density,
    onSelect,
    onToggle,
    onSelectAll,
    allChecked,
}: InboxTableProps) {
    return (
        <div className="min-h-0 flex-1 overflow-auto">
            {/* min-w: en viewports angostos la tabla scrollea dentro del
                wrapper en vez de aplastar las columnas. */}
            <table className="w-full min-w-[760px] border-collapse">
                <thead>
                    <tr className="sticky top-0 z-10 border-b border-border bg-surface-3 text-[10px] font-semibold tracking-[0.08em] text-fg-3 uppercase">
                        <th className="w-[34px] px-2.5 py-2 text-left">
                            <span
                                className="inline-grid cursor-pointer place-items-center rounded-sm border border-border-strong select-none"
                                style={{ width: 14, height: 14 }}
                                role="checkbox"
                                aria-checked={allChecked}
                                onClick={onSelectAll}
                            >
                                {allChecked && (
                                    <svg
                                        width="9"
                                        height="7"
                                        viewBox="0 0 9 7"
                                        fill="none"
                                        aria-hidden="true"
                                    >
                                        <path
                                            d="M1 3.5L3.5 6L8 1"
                                            stroke="currentColor"
                                            strokeWidth="1.5"
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                        />
                                    </svg>
                                )}
                            </span>
                        </th>
                        <th className="w-24 px-2.5 py-2 text-left">Sev</th>
                        <th className="px-2.5 py-2 text-left">Incidente</th>
                        <th className="w-36 px-2.5 py-2 text-left">Activo</th>
                        <th className="w-28 px-2.5 py-2 text-left">
                            Conductor
                        </th>
                        <th className="w-40 px-2.5 py-2 text-left">Asignado</th>
                        <th className="w-28 px-2.5 py-2 text-left">Estado</th>
                        <th className="w-24 px-2.5 py-2 text-left">SLA</th>
                        <th className="w-20 px-2.5 py-2 text-left">Edad</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((incident) => (
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
}
