import {
    AlertOctagon,
    Camera,
    Check,
    ChevronDown,
    ChevronRight,
    Info,
    Plug,
    Radar,
    RefreshCw,
    TimerOff,
    User,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    IncidentDetail,
    IncidentTimelineEntry,
    TimelineEntryType,
} from '@/types/sam';
import { mediaResultLabel } from './media-verdict';

const TYPE_ICON: Record<TimelineEntryType, LucideIcon> = {
    system: RefreshCw,
    webhook: Plug,
    ai: Radar,
    user: User,
    critical: AlertOctagon,
    assign: ChevronRight,
    comment: Info,
    media: Camera,
    sla: TimerOff,
    resolved: Check,
};

type ActivityNode =
    | { kind: 'entry'; entry: IncidentTimelineEntry }
    | { kind: 'media-group'; entries: IncidentTimelineEntry[] };

/**
 * Colapsa rachas consecutivas de `media_assessed` en un solo nodo agregado:
 * 20 evaluaciones de imagen dejan de ser 20 filas con párrafo cada una.
 */
function groupTimeline(timeline: IncidentTimelineEntry[]): ActivityNode[] {
    const nodes: ActivityNode[] = [];

    for (const entry of timeline) {
        const last = nodes[nodes.length - 1];

        if (entry.entryType === 'media_assessed') {
            if (last?.kind === 'media-group') {
                last.entries.push(entry);
            } else {
                nodes.push({ kind: 'media-group', entries: [entry] });
            }

            continue;
        }

        nodes.push({ kind: 'entry', entry });
    }

    return nodes;
}

/** Día + hora cuando el timeline abarca más de un día; solo hora si no. */
function useTimestamps(timeline: IncidentTimelineEntry[]) {
    return useMemo(() => {
        const days = new Set(
            timeline
                .map((entry) => entry.tsIso?.slice(0, 10))
                .filter((day): day is string => Boolean(day)),
        );
        const multiDay = days.size > 1;

        return (entry: IncidentTimelineEntry): string => {
            if (!multiDay || !entry.tsIso) {
                return entry.ts;
            }

            const date = new Date(entry.tsIso);

            if (Number.isNaN(date.getTime())) {
                return entry.ts;
            }

            const day = date.toLocaleDateString('es', {
                day: 'numeric',
                month: 'short',
            });

            return `${day} · ${entry.ts.slice(0, 5)}`;
        };
    }, [timeline]);
}

function EntryIcon({ entry }: { entry: IncidentTimelineEntry }) {
    const Icon = TYPE_ICON[entry.type] ?? Info;
    const isAi = entry.type === 'ai' || entry.type === 'media';
    const isCritical = entry.type === 'critical' || entry.type === 'sla';

    return (
        <span
            className={cn(
                'z-[1] inline-grid size-[22px] place-items-center rounded-full border',
                isAi
                    ? 'border-ai-accent/40 bg-ai-accent-bg text-ai-accent'
                    : isCritical
                      ? 'border-transparent bg-severity-critical text-white'
                      : entry.type === 'resolved'
                        ? 'border-health-ok/40 bg-health-ok/10 text-health-ok'
                        : 'border-border bg-surface-2 text-fg-2',
            )}
        >
            <Icon size={11} strokeWidth={isCritical ? 2 : 1.5} />
        </span>
    );
}

function MediaGroupNode({
    entries,
    formatTs,
}: {
    entries: IncidentTimelineEntry[];
    formatTs: (entry: IncidentTimelineEntry) => string;
}) {
    const [expanded, setExpanded] = useState(false);

    const counts = useMemo(() => {
        const acc = new Map<string, number>();

        for (const entry of entries) {
            const key = entry.meta?.result ?? 'sin veredicto';
            acc.set(key, (acc.get(key) ?? 0) + 1);
        }

        // Decisivos primero: contradice/confirma pesan más que "no concluyente".
        const order = [
            'contradicts_event',
            'confirms_event',
            'inconclusive',
            'low_quality',
            'unavailable',
        ];

        return [...acc.entries()].sort(
            (a, b) => order.indexOf(a[0]) - order.indexOf(b[0]),
        );
    }, [entries]);

    const first = entries[0];
    const last = entries[entries.length - 1];

    return (
        <li className="grid grid-cols-[24px_minmax(0,1fr)_auto] items-start gap-2 py-1.5 text-xs">
            <EntryIcon entry={first} />

            <div className="min-w-0">
                <div>
                    <strong className="font-semibold text-fg-1">SAM</strong>{' '}
                    <span className="text-fg-2">
                        evaluó {entries.length}{' '}
                        {entries.length === 1 ? 'media' : 'medias'}
                    </span>
                </div>
                <div className="mt-0.5 text-2xs text-fg-3">
                    {counts
                        .map(
                            ([result, count]) =>
                                `${count} ${mediaResultLabel(result).toLowerCase()}`,
                        )
                        .join(' · ')}
                </div>
                <button
                    type="button"
                    className="mt-1 flex items-center gap-1 text-2xs text-fg-3 hover:text-fg-1"
                    onClick={() => setExpanded((v) => !v)}
                >
                    {expanded ? (
                        <ChevronDown size={11} strokeWidth={1.75} />
                    ) : (
                        <ChevronRight size={11} strokeWidth={1.75} />
                    )}
                    {expanded ? 'Ocultar detalle' : 'Ver cada evaluación'}
                </button>

                {expanded && (
                    <ul className="mt-1.5 flex flex-col gap-1.5 border-l border-border pl-2.5">
                        {entries.map((entry, idx) => (
                            <li key={idx} className="min-w-0">
                                <div className="flex items-baseline gap-2">
                                    <span className="font-mono text-3xs whitespace-nowrap text-fg-3">
                                        {formatTs(entry)}
                                    </span>
                                    <span className="text-2xs font-medium text-fg-2">
                                        {mediaResultLabel(entry.meta?.result)}
                                        {entry.meta?.confidence != null &&
                                            ` · ${Math.round(entry.meta.confidence * 100)} %`}
                                    </span>
                                </div>
                                {entry.sub && (
                                    <p className="mt-0.5 line-clamp-2 text-2xs leading-normal text-fg-3">
                                        {entry.sub}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <span
                className="font-mono text-3xs whitespace-nowrap text-fg-3"
                title={
                    first.tsIso && last.tsIso
                        ? `${formatDateTime(first.tsIso)} a ${formatDateTime(last.tsIso)}`
                        : undefined
                }
            >
                {formatTs(first) === formatTs(last)
                    ? formatTs(first)
                    : `${formatTs(first)} a ${formatTs(last)}`}
            </span>
        </li>
    );
}

interface ActivityProps {
    incident: IncidentDetail;
    /** Panel: muestra solo los últimos N nodos agrupados. */
    limit?: number;
    className?: string;
}

export function Activity({ incident, limit, className }: ActivityProps) {
    const formatTs = useTimestamps(incident.timeline);
    const nodes = useMemo(
        () => groupTimeline(incident.timeline),
        [incident.timeline],
    );

    const visible = limit !== undefined ? nodes.slice(-limit) : nodes;
    const hidden = nodes.length - visible.length;

    return (
        <section className={className}>
            <h3 className="mb-2 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                Actividad
                {incident.timeline.length > 0 && (
                    <span className="ml-1.5 font-mono text-fg-3 normal-case">
                        {incident.timeline.length}
                    </span>
                )}
            </h3>

            {hidden > 0 && (
                <p className="mb-1.5 text-2xs text-fg-3">
                    {hidden}{' '}
                    {hidden === 1 ? 'entrada previa' : 'entradas previas'} en el
                    detalle completo.
                </p>
            )}

            <ol className="relative m-0 list-none p-0 before:absolute before:top-2.5 before:bottom-2.5 before:left-[11px] before:w-px before:bg-border">
                {visible.map((node, idx) =>
                    node.kind === 'media-group' ? (
                        <MediaGroupNode
                            key={idx}
                            entries={node.entries}
                            formatTs={formatTs}
                        />
                    ) : (
                        <li
                            key={idx}
                            className="grid grid-cols-[24px_minmax(0,1fr)_auto] items-start gap-2 py-1.5 text-xs"
                        >
                            <EntryIcon entry={node.entry} />

                            <div className="min-w-0">
                                <div>
                                    <strong className="font-semibold text-fg-1">
                                        {node.entry.actor}
                                    </strong>{' '}
                                    <span className="text-fg-2">
                                        {node.entry.text}
                                    </span>
                                </div>
                                {node.entry.sub && (
                                    <div className="mt-0.5 line-clamp-3 text-2xs text-fg-3">
                                        {node.entry.sub}
                                    </div>
                                )}
                            </div>

                            <span
                                className="font-mono text-3xs whitespace-nowrap text-fg-3"
                                title={
                                    node.entry.tsIso
                                        ? formatDateTime(node.entry.tsIso)
                                        : undefined
                                }
                            >
                                {formatTs(node.entry)}
                            </span>
                        </li>
                    ),
                )}
            </ol>
        </section>
    );
}
