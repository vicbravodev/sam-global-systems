import { Inbox } from 'lucide-react';
import { EmptyState } from '@/components/ui/empty-state';
import type { InboxDensity, InboxLayout, MockIncident } from '@/types/sam';
import { InboxGrouped } from './inbox-grouped';
import { InboxStream } from './inbox-stream';
import { InboxTable } from './inbox-table';

export interface InboxBodyProps {
    /** Whether the team has any incident at all (vs. none in this tab). */
    hasIncidents: boolean;
    rows: MockIncident[];
    layout: InboxLayout;
    density: InboxDensity;
    selectedId: string | null;
    selectedSet: Set<string>;
    allChecked: boolean;
    onSelect: (id: string) => void;
    onToggle: (id: string) => void;
    onSelectAll: () => void;
    currentUserId: number | null;
    claimPendingId: number | null;
    /** Ausente cuando el rol no puede tomar incidentes. */
    onClaimToggle?: (incident: MockIncident) => void;
}

/** The inbox rows in the chosen layout, or the matching empty state. */
export function InboxBody({
    hasIncidents,
    rows,
    layout,
    density,
    selectedId,
    selectedSet,
    allChecked,
    onSelect,
    onToggle,
    onSelectAll,
    currentUserId,
    claimPendingId,
    onClaimToggle,
}: InboxBodyProps) {
    if (!hasIncidents) {
        return (
            <EmptyState
                className="min-h-0 flex-1"
                icon={Inbox}
                title="Sin incidentes"
                description="Cuando el pipeline genere incidentes para tu equipo aparecerán aquí en tiempo real."
            />
        );
    }

    if (rows.length === 0) {
        return (
            <EmptyState
                className="min-h-0 flex-1"
                icon={Inbox}
                title="Nada en esta pestaña"
                description="No hay incidentes que coincidan con la pestaña o los filtros activos. Cambia de pestaña o limpia los filtros."
            />
        );
    }

    return (
        <>
            {layout === 'table' && (
                <InboxTable
                    rows={rows}
                    selectedId={selectedId}
                    selectedSet={selectedSet}
                    density={density}
                    onSelect={onSelect}
                    onToggle={onToggle}
                    onSelectAll={onSelectAll}
                    allChecked={allChecked}
                    currentUserId={currentUserId}
                    claimPendingId={claimPendingId}
                    onClaimToggle={onClaimToggle}
                />
            )}
            {layout === 'grouped' && (
                <InboxGrouped
                    rows={rows}
                    selectedId={selectedId}
                    selectedSet={selectedSet}
                    density={density}
                    onSelect={onSelect}
                    onToggle={onToggle}
                    currentUserId={currentUserId}
                    claimPendingId={claimPendingId}
                    onClaimToggle={onClaimToggle}
                />
            )}
            {layout === 'stream' && (
                <InboxStream
                    rows={rows}
                    selectedId={selectedId}
                    onSelect={onSelect}
                />
            )}
        </>
    );
}
