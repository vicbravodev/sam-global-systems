import { ShieldAlert, Sparkles, Truck } from 'lucide-react';
import { CellEmpty } from '@/components/sam/data-table';
import type { DataTableColumn } from '@/components/sam/data-table';
import { EntityAvatar } from '@/components/sam/entity-avatar';
import { toSeverity } from '@/components/sam/event-severity';
import { ProviderTag } from '@/components/sam/provider-tag';
import { RelativeTime } from '@/components/sam/relative-time';
import { SeverityBadge } from '@/components/sam/severity-badge';
import { formatDateTime } from '@/lib/format';
import { providerDescriptionLabel } from '@/lib/labels';
import { formatClock, dayLabel, minutesSince } from '@/lib/time';
import type { EventRow } from '@/types/events';
import { EventCategoryIcon } from './event-category-icon';
import { PipelineStatusPill } from './pipeline-status';

function WhenCell({ iso }: { iso: string | null }) {
    if (iso === null) {
        return <CellEmpty />;
    }

    return (
        <span
            className="flex flex-col whitespace-nowrap"
            title={formatDateTime(iso)}
        >
            <RelativeTime minutes={minutesSince(iso)} className="text-fg-1" />
            <span className="font-mono text-3xs text-fg-3 tabular-nums">
                {dayLabel(iso)} · {formatClock(iso)}
            </span>
        </span>
    );
}

function EventCell({ event }: { event: EventRow }) {
    return (
        <span className="flex items-center gap-2.5">
            <span className="grid size-7 shrink-0 place-items-center rounded-md border border-border bg-surface-2">
                <EventCategoryIcon code={event.categoryCode} />
            </span>
            <span className="flex min-w-0 flex-col">
                <span className="truncate text-sm font-medium text-fg-1">
                    {event.eventType ??
                        event.eventTypeCode ??
                        `Evento #${event.id}`}
                </span>
                <span className="truncate text-3xs text-fg-3">
                    {[
                        event.category,
                        providerDescriptionLabel(
                            event.description,
                            event.eventType,
                        ),
                    ]
                        .filter(Boolean)
                        .join(' · ') || '—'}
                </span>
            </span>
        </span>
    );
}

function PipelineCell({ event }: { event: EventRow }) {
    return (
        <span className="flex items-center gap-1.5">
            <PipelineStatusPill
                status={event.status}
                label={event.statusLabel}
            />
            {event.hasEvaluation && (
                <span
                    title="Evaluado por IA"
                    className="grid size-5 place-items-center rounded-sm border border-ai-accent/40 bg-ai-accent-bg text-ai-accent"
                >
                    <Sparkles size={11} aria-label="Evaluado por IA" />
                </span>
            )}
            {event.hasIncident && (
                <span
                    title="Abrió un incidente"
                    className="grid size-5 place-items-center rounded-sm border border-severity-high/40 bg-severity-high/10 text-severity-high"
                >
                    <ShieldAlert size={11} aria-label="Abrió un incidente" />
                </span>
            )}
        </span>
    );
}

export const EVENT_COLUMNS: DataTableColumn<EventRow>[] = [
    {
        key: 'occurredAt',
        header: 'Cuándo',
        width: 'w-36',
        sortValue: (event) =>
            event.occurredAt ? Date.parse(event.occurredAt) : null,
        cell: (event) => <WhenCell iso={event.occurredAt} />,
    },
    {
        key: 'event',
        header: 'Evento',
        sortValue: (event) => event.eventType ?? event.eventTypeCode,
        cell: (event) => <EventCell event={event} />,
    },
    {
        key: 'severity',
        header: 'Severidad',
        width: 'w-28',
        sortValue: (event) => event.severityLabel,
        cell: (event) =>
            event.severity ? (
                <SeverityBadge level={toSeverity(event.severity)} />
            ) : (
                <CellEmpty />
            ),
    },
    {
        key: 'asset',
        header: 'Unidad',
        width: 'w-40',
        sortValue: (event) => event.asset,
        cell: (event) =>
            event.asset ? (
                <span className="flex items-center gap-1.5 text-xs text-fg-1">
                    <Truck
                        size={12}
                        strokeWidth={1.75}
                        className="shrink-0 text-fg-3"
                        aria-hidden="true"
                    />
                    <span className="truncate">{event.asset}</span>
                </span>
            ) : (
                <CellEmpty />
            ),
    },
    {
        key: 'driver',
        header: 'Conductor',
        width: 'w-44',
        sortValue: (event) => event.driver,
        cell: (event) =>
            event.driver ? (
                <span className="flex items-center gap-2">
                    <EntityAvatar name={event.driver} size={20} />
                    <span className="truncate text-xs text-fg-1">
                        {event.driver}
                    </span>
                </span>
            ) : (
                <CellEmpty variant="person" />
            ),
    },
    {
        key: 'provider',
        header: 'Origen',
        width: 'w-24',
        cell: (event) =>
            event.provider ? (
                <ProviderTag name={event.provider} />
            ) : (
                <span className="text-2xs text-fg-3">interno</span>
            ),
    },
    {
        key: 'status',
        header: 'Pipeline',
        width: 'w-44',
        sortValue: (event) => event.status,
        cell: (event) => <PipelineCell event={event} />,
    },
];
