import { Camera, Cpu, MapPin, Navigation, Truck } from 'lucide-react';
import type * as React from 'react';
import { useMemo } from 'react';
import { CellEmpty, DataTable } from '@/components/sam/data-table';
import type { DataTableColumn } from '@/components/sam/data-table';
import { EntityAvatar } from '@/components/sam/entity-avatar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatNumber } from '@/lib/format';
import { isFresh } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { AssetRow } from '@/types/assets';
import { AssetSignal } from './asset-signal';
import { AssetStatusBadge } from './asset-status-badge';
import { MonitoringSwitch } from './monitoring-switch';
import { PlateChip, vehicleTitle } from './vehicle-line';

const MOVING_SPEED_KPH = 5;

const DEVICE_ICONS: Record<string, typeof Cpu> = {
    gateway: Cpu,
    camera: Camera,
    dashcam: Camera,
    gps_tracker: MapPin,
};

function DevicesCell({ devices }: { devices: AssetRow['devices'] }) {
    if (devices.length === 0) {
        return <CellEmpty />;
    }

    return (
        <span className="flex items-center gap-1">
            {devices.map((device) => {
                const Icon = DEVICE_ICONS[device.deviceType] ?? Cpu;

                return (
                    <Tooltip key={device.id}>
                        <TooltipTrigger asChild>
                            <span className="grid size-6 place-items-center rounded-sm border border-border bg-surface-2 text-fg-2">
                                <Icon
                                    size={12}
                                    strokeWidth={1.75}
                                    aria-label={device.label}
                                />
                            </span>
                        </TooltipTrigger>
                        <TooltipContent side="top">
                            {device.label}
                            {device.externalDeviceId && (
                                <span className="ml-1 font-mono text-fg-3">
                                    {device.externalDeviceId}
                                </span>
                            )}
                        </TooltipContent>
                    </Tooltip>
                );
            })}
        </span>
    );
}

function LocationCell({ location }: { location: AssetRow['lastLocation'] }) {
    if (location === null) {
        return <CellEmpty />;
    }

    const fresh = isFresh(location.recordedAt);
    const moving =
        fresh && location.speed !== null && location.speed > MOVING_SPEED_KPH;

    return (
        <span className="flex min-w-0 flex-col">
            <span className="truncate text-xs text-fg-2">
                {location.formattedLocation ??
                    `${location.latitude.toFixed(5)}, ${location.longitude.toFixed(5)}`}
            </span>
            {location.speed !== null && (
                <span
                    className={cn(
                        'inline-flex items-center gap-1 font-mono text-3xs tabular-nums',
                        moving ? 'text-severity-low' : 'text-fg-3',
                    )}
                >
                    {moving && (
                        <Navigation
                            size={9}
                            className="shrink-0"
                            style={{
                                transform: `rotate(${(location.heading ?? 0) - 45}deg)`,
                            }}
                            aria-hidden="true"
                        />
                    )}
                    {formatNumber(location.speed, {
                        maximumFractionDigits: 0,
                    })}{' '}
                    km/h
                    {moving ? ' · en ruta' : fresh ? ' · detenido' : ''}
                </span>
            )}
        </span>
    );
}

function DriverCell({ driver }: { driver: AssetRow['driver'] }) {
    if (driver === null) {
        return <CellEmpty variant="person" />;
    }

    return (
        <span className="flex items-center gap-2">
            <EntityAvatar name={driver.name} size={22} />
            <span className="flex min-w-0 flex-col">
                <span className="truncate text-xs text-fg-1">
                    {driver.name}
                </span>
                {driver.employeeCode && (
                    <span className="font-mono text-3xs text-fg-3">
                        {driver.employeeCode}
                    </span>
                )}
            </span>
        </span>
    );
}

const COLUMNS: DataTableColumn<AssetRow>[] = [
    {
        key: 'name',
        header: 'Unidad',
        sortValue: (asset) => asset.name,
        cell: (asset) => {
            const title = vehicleTitle(asset.vehicle);

            return (
                <span className="flex items-center gap-2.5">
                    <EntityAvatar
                        name={asset.name}
                        size={28}
                        shape="square"
                        icon={Truck}
                    />
                    <span className="flex min-w-0 flex-col">
                        <span className="flex items-center gap-1.5">
                            <span className="truncate text-sm font-medium text-fg-1">
                                {asset.name}
                            </span>
                            {asset.vehicle?.plate && (
                                <PlateChip plate={asset.vehicle.plate} />
                            )}
                        </span>
                        <span className="truncate text-3xs text-fg-3">
                            {[asset.code, title, asset.type?.name]
                                .filter(Boolean)
                                .join(' · ') || '—'}
                        </span>
                    </span>
                </span>
            );
        },
    },
    {
        key: 'status',
        header: 'Estado',
        width: 'w-32',
        sortValue: (asset) => asset.status,
        cell: (asset) => <AssetStatusBadge status={asset.status} />,
    },
    {
        key: 'driver',
        header: 'Conductor',
        width: 'w-44',
        sortValue: (asset) => asset.driver?.name ?? null,
        cell: (asset) => <DriverCell driver={asset.driver} />,
    },
    {
        key: 'location',
        header: 'Última posición',
        width: 'w-64',
        sortValue: (asset) => asset.lastLocation?.speed ?? null,
        cell: (asset) => <LocationCell location={asset.lastLocation} />,
    },
    {
        key: 'devices',
        header: 'Equipo',
        width: 'w-28',
        cell: (asset) => <DevicesCell devices={asset.devices} />,
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
    /** Needed by the monitoring switch to PUT the new state. */
    teamSlug?: string | null;
}

export function AssetsTable({
    rows,
    onSelect,
    empty,
    teamSlug = null,
}: AssetsTableProps) {
    // The hardware column only earns its space once at least one unit on the
    // page reports a device; otherwise it is a column of dashes. The
    // monitoring switch goes first: it is the one thing the client edits.
    const columns = useMemo(
        () => [
            {
                key: 'monitoring',
                header: 'Vigilancia',
                width: 'w-36',
                sortValue: (asset: AssetRow) => asset.monitoringState,
                cell: (asset: AssetRow) => (
                    <MonitoringSwitch
                        assetId={asset.id}
                        assetName={asset.name}
                        state={asset.monitoringState}
                        teamSlug={teamSlug}
                        withLabel
                    />
                ),
            } satisfies DataTableColumn<AssetRow>,
            ...COLUMNS.filter(
                (column) =>
                    column.key !== 'devices' ||
                    rows.some((asset) => asset.devices.length > 0),
            ),
        ],
        [rows, teamSlug],
    );

    return (
        <DataTable
            columns={columns}
            rows={rows}
            rowKey={(asset) => asset.id}
            onRowClick={(asset) => onSelect(asset.id)}
            empty={empty}
        />
    );
}
