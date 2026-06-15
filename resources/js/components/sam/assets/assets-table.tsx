import type * as React from 'react';
import { CellEmpty, DataTable } from '@/components/sam/data-table';
import type { DataTableColumn } from '@/components/sam/data-table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { AssetRow } from '@/types/assets';
import { AssetSignal } from './asset-signal';
import { AssetStatusBadge } from './asset-status-badge';

function DevicesCell({ devices }: { devices: AssetRow['devices'] }) {
    if (devices.length === 0) {
        return <CellEmpty />;
    }

    const [first, ...rest] = devices;

    return (
        <span className="flex items-center gap-1.5">
            <span className="truncate font-mono text-2xs text-fg-2">
                {first.deviceType}
            </span>
            {rest.length > 0 && (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <span className="rounded-full bg-surface-3 px-1.5 py-0.5 font-mono text-3xs font-semibold text-fg-2">
                            +{rest.length}
                        </span>
                    </TooltipTrigger>
                    <TooltipContent side="top">
                        {rest.map((device) => device.deviceType).join(' · ')}
                    </TooltipContent>
                </Tooltip>
            )}
        </span>
    );
}

function LocationCell({ location }: { location: AssetRow['lastLocation'] }) {
    if (location === null) {
        return <CellEmpty />;
    }

    if (location.formattedLocation) {
        return (
            <span className="truncate text-xs text-fg-2">
                {location.formattedLocation}
            </span>
        );
    }

    return (
        <span className="font-mono text-2xs text-fg-2 tabular-nums">
            {location.latitude.toFixed(5)}, {location.longitude.toFixed(5)}
        </span>
    );
}

const COLUMNS: DataTableColumn<AssetRow>[] = [
    {
        key: 'name',
        header: 'Activo',
        sortValue: (asset) => asset.name,
        cell: (asset) => (
            <div className="flex flex-col">
                <span className="truncate text-sm font-medium text-fg-1">
                    {asset.name}
                </span>
                {asset.code && (
                    <span className="font-mono text-3xs text-fg-3">
                        {asset.code}
                    </span>
                )}
            </div>
        ),
    },
    {
        key: 'status',
        header: 'Estado',
        width: 'w-32',
        sortValue: (asset) => asset.status,
        cell: (asset) => <AssetStatusBadge status={asset.status} />,
    },
    {
        key: 'type',
        header: 'Tipo',
        width: 'w-32',
        sortValue: (asset) => asset.type?.name ?? null,
        cell: (asset) => (
            <span className="text-xs text-fg-2">{asset.type?.name ?? '—'}</span>
        ),
    },
    {
        key: 'devices',
        header: 'Dispositivos',
        width: 'w-44',
        cell: (asset) => <DevicesCell devices={asset.devices} />,
    },
    {
        key: 'location',
        header: 'Última posición',
        width: 'w-56',
        cell: (asset) => <LocationCell location={asset.lastLocation} />,
    },
    {
        key: 'signal',
        header: 'Señal',
        width: 'w-44',
        sortValue: (asset) =>
            asset.lastSignalAt ? Date.parse(asset.lastSignalAt) : null,
        cell: (asset) => (
            <AssetSignal
                lastSignalAt={asset.lastSignalAt}
                hasDevice={asset.devices.length > 0}
            />
        ),
    },
];

interface AssetsTableProps {
    rows: AssetRow[];
    onSelect: (id: number) => void;
    empty?: React.ReactNode;
}

export function AssetsTable({ rows, onSelect, empty }: AssetsTableProps) {
    return (
        <DataTable
            columns={COLUMNS}
            rows={rows}
            rowKey={(asset) => asset.id}
            onRowClick={(asset) => onSelect(asset.id)}
            empty={empty}
        />
    );
}
