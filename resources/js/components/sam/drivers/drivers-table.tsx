import type * as React from 'react';
import { useMemo } from 'react';
import { CellEmpty, DataTable } from '@/components/sam/data-table';
import type { DataTableColumn } from '@/components/sam/data-table';
import { RelativeTime } from '@/components/sam/relative-time';
import { cn } from '@/lib/utils';
import type { DriverRow } from '@/types/drivers';
import { DriverStatusBadge } from './driver-status-badge';

function minutesSince(iso: string): number {
    return Math.max(0, Math.floor((Date.now() - Date.parse(iso)) / 60000));
}

function AssetCell({ asset }: { asset: DriverRow['currentAsset'] }) {
    if (asset === null) {
        return <CellEmpty />;
    }

    return (
        <div className="flex flex-col">
            <span className="truncate text-xs text-fg-2">{asset.name}</span>
            {asset.code && (
                <span className="font-mono text-3xs text-fg-3">
                    {asset.code}
                </span>
            )}
        </div>
    );
}

function RiskCell({ score }: { score: number | null }) {
    if (score === null) {
        return <CellEmpty />;
    }

    return (
        <span
            className={cn(
                'font-mono text-xs font-semibold tabular-nums',
                score >= 70
                    ? 'text-severity-critical'
                    : score >= 40
                      ? 'text-severity-medium'
                      : 'text-severity-low',
            )}
        >
            {score.toFixed(0)}
        </span>
    );
}

const COLUMNS: DataTableColumn<DriverRow>[] = [
    {
        key: 'name',
        header: 'Conductor',
        sortValue: (driver) => driver.fullName,
        cell: (driver) => (
            <div className="flex flex-col">
                <span className="truncate text-sm font-medium text-fg-1">
                    {driver.fullName}
                </span>
                {driver.employeeCode && (
                    <span className="font-mono text-3xs text-fg-3">
                        {driver.employeeCode}
                    </span>
                )}
            </div>
        ),
    },
    {
        key: 'status',
        header: 'Estado',
        width: 'w-36',
        sortValue: (driver) => driver.status,
        cell: (driver) => <DriverStatusBadge status={driver.status} />,
    },
    {
        key: 'asset',
        header: 'Asset asignado',
        width: 'w-48',
        cell: (driver) => <AssetCell asset={driver.currentAsset} />,
    },
    {
        key: 'risk',
        header: 'Riesgo',
        width: 'w-24',
        sortValue: (driver) => driver.riskScore,
        cell: (driver) => <RiskCell score={driver.riskScore} />,
    },
    {
        key: 'phone',
        header: 'Teléfono',
        width: 'w-40',
        cell: (driver) =>
            driver.phone ? (
                <span className="font-mono text-2xs text-fg-2 tabular-nums">
                    {driver.phone}
                </span>
            ) : (
                <CellEmpty />
            ),
    },
    {
        key: 'lastSeen',
        header: 'Visto',
        width: 'w-28',
        sortValue: (driver) =>
            driver.lastSeenAt ? Date.parse(driver.lastSeenAt) : null,
        cell: (driver) =>
            driver.lastSeenAt ? (
                <RelativeTime minutes={minutesSince(driver.lastSeenAt)} />
            ) : (
                <CellEmpty />
            ),
    },
];

interface DriversTableProps {
    rows: DriverRow[];
    onSelect: (id: number) => void;
    empty?: React.ReactNode;
}

export function DriversTable({ rows, onSelect, empty }: DriversTableProps) {
    // Columnas sin un solo dato en todo el set (asset/riesgo/teléfono aún no
    // sincronizados) se ocultan en vez de pintar "—" en cada fila.
    const columns = useMemo(
        () =>
            COLUMNS.filter((column) => {
                if (column.key === 'asset') {
                    return rows.some((d) => d.currentAsset !== null);
                }

                if (column.key === 'risk') {
                    return rows.some((d) => d.riskScore !== null);
                }

                if (column.key === 'phone') {
                    return rows.some((d) => d.phone);
                }

                return true;
            }),
        [rows],
    );

    return (
        <DataTable
            columns={columns}
            rows={rows}
            rowKey={(driver) => driver.id}
            onRowClick={(driver) => onSelect(driver.id)}
            empty={empty}
        />
    );
}
