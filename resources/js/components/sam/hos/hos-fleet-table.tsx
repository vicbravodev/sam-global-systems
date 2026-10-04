import { CellEmpty } from '@/components/sam/data-table/cell-empty';
import { DataTable } from '@/components/sam/data-table/data-table';
import type { DataTableColumn } from '@/components/sam/data-table/data-table';
import { StatusBadge } from '@/components/sam/status-badge';
import { relativeLabel } from '@/lib/time';
import { TONE_TEXT } from '@/lib/tone';
import { cn } from '@/lib/utils';
import type { HosFleetRow } from '@/types/hos';
import { HOS_SITUATION_LABELS, HOS_URGENCY } from './copy';
import { HosStatusBadge } from './hos-status-badge';
import { nearestLabel } from './lib';

export interface HosFleetTableProps {
    rows: HosFleetRow[];
    onSelect: (row: HosFleetRow) => void;
}

/** Choferes vigilados en el orden del servidor (lo más urgente primero). */
export function HosFleetTable({ rows, onSelect }: HosFleetTableProps) {
    const columns: DataTableColumn<HosFleetRow>[] = [
        {
            key: 'driver',
            header: 'Chofer',
            cell: (row) => (
                <div className="flex min-w-0 flex-col">
                    <span className="truncate text-sm font-medium text-fg-1">
                        {row.driver.fullName}
                    </span>
                    <span className="truncate text-2xs text-fg-3">
                        {row.asset?.name ?? 'Sin tracto'}
                    </span>
                </div>
            ),
        },
        {
            key: 'urgency',
            header: 'Situación',
            width: 'w-44',
            cell: (row) => (
                <StatusBadge size="sm" dot {...HOS_URGENCY[row.urgency]} />
            ),
        },
        {
            key: 'status',
            header: 'Estado',
            width: 'w-48',
            cell: (row) => (
                <HosStatusBadge
                    dutyStatus={row.dutyStatus}
                    appDisconnected={row.appDisconnected}
                />
            ),
        },
        {
            key: 'nearest',
            header: 'Reloj más corto',
            width: 'w-36',
            numeric: true,
            cell: (row) => nearestLabel(row),
        },
        {
            key: 'episodes',
            header: 'Abierto',
            cell: (row) =>
                row.openEpisodes.length === 0 ? (
                    <CellEmpty />
                ) : (
                    <span className="text-xs text-fg-2">
                        {row.openEpisodes
                            .map(
                                (episode) =>
                                    HOS_SITUATION_LABELS[episode.situation],
                            )
                            .join(' · ')}
                    </span>
                ),
        },
        {
            key: 'observed',
            header: 'Lectura',
            width: 'w-36',
            cell: (row) => (
                <div className="flex flex-col">
                    <span
                        className={cn(
                            'text-xs',
                            row.stale ? TONE_TEXT.warn : 'text-fg-3',
                        )}
                    >
                        {relativeLabel(row.observedAt)}
                    </span>
                    {row.stale ? (
                        <span className={cn('text-2xs', TONE_TEXT.warn)}>
                            Sin lectura reciente
                        </span>
                    ) : null}
                </div>
            ),
        },
    ];

    return (
        <DataTable
            columns={columns}
            rows={rows}
            rowKey={(row) => row.driver.id}
            onRowClick={onSelect}
        />
    );
}
