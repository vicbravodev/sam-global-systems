import { Phone, Truck } from 'lucide-react';
import type * as React from 'react';
import { useMemo } from 'react';
import { CellEmpty, DataTable } from '@/components/sam/data-table';
import type { DataTableColumn } from '@/components/sam/data-table';
import { EntityAvatar } from '@/components/sam/entity-avatar';
import { RelativeTime } from '@/components/sam/relative-time';
import { RiskBar } from '@/components/sam/risk-gauge';
import { formatDateTime } from '@/lib/format';
import { isFresh, minutesSince } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { DriverColumnPresence, DriverRow } from '@/types/drivers';
import { DriverStatusBadge } from './driver-status-badge';

function AssetCell({ asset }: { asset: DriverRow['currentAsset'] }) {
    if (asset === null) {
        return <CellEmpty variant="person" className="not-italic" />;
    }

    return (
        <span className="flex items-center gap-2">
            <Truck
                size={13}
                strokeWidth={1.75}
                className="shrink-0 text-fg-3"
                aria-hidden="true"
            />
            <span className="flex min-w-0 flex-col">
                <span className="truncate text-xs text-fg-1">{asset.name}</span>
                {asset.code && (
                    <span className="font-mono text-3xs text-fg-3">
                        {asset.code}
                    </span>
                )}
            </span>
        </span>
    );
}

function ActivityCell({ driver }: { driver: DriverRow }) {
    if (driver.riskScore === null) {
        return <CellEmpty />;
    }

    const parts = [
        driver.incidentsCount > 0 ? `${driver.incidentsCount} inc.` : null,
        driver.harshEventsCount > 0
            ? `${driver.harshEventsCount} bruscos`
            : null,
    ].filter(Boolean);

    return (
        <span
            className={cn(
                'font-mono text-2xs tabular-nums',
                driver.incidentsCount > 0 ? 'text-fg-1' : 'text-fg-3',
            )}
        >
            {parts.length > 0 ? parts.join(' · ') : 'sin actividad'}
        </span>
    );
}

function SeenCell({ iso }: { iso: string | null }) {
    if (iso === null) {
        return <CellEmpty />;
    }

    const fresh = isFresh(iso);

    return (
        <span
            className="inline-flex items-center gap-1.5"
            title={formatDateTime(iso)}
        >
            <span
                className={cn(
                    'size-1.5 rounded-full',
                    fresh ? 'bg-severity-low' : 'bg-fg-disabled',
                )}
                aria-hidden="true"
            />
            <RelativeTime minutes={minutesSince(iso)} />
        </span>
    );
}

const COLUMNS: DataTableColumn<DriverRow>[] = [
    {
        key: 'name',
        header: 'Conductor',
        sortValue: (driver) => driver.fullName,
        cell: (driver) => (
            <span className="flex items-center gap-2.5">
                <EntityAvatar name={driver.fullName} size={28} />
                <span className="flex min-w-0 flex-col">
                    <span className="truncate text-sm font-medium text-fg-1">
                        {driver.fullName}
                    </span>
                    {driver.employeeCode && (
                        <span className="font-mono text-3xs text-fg-3">
                            {driver.employeeCode}
                        </span>
                    )}
                </span>
            </span>
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
        header: 'Unidad asignada',
        width: 'w-48',
        sortValue: (driver) => driver.currentAsset?.name ?? null,
        cell: (driver) => <AssetCell asset={driver.currentAsset} />,
    },
    {
        key: 'risk',
        header: 'Riesgo',
        width: 'w-36',
        sortValue: (driver) => driver.riskScore,
        cell: (driver) => (
            <RiskBar
                score={driver.riskScore}
                level={driver.riskLevel}
                trend={driver.riskTrend}
            />
        ),
    },
    {
        key: 'activity',
        header: '30 días',
        width: 'w-32',
        sortValue: (driver) =>
            driver.riskScore === null
                ? null
                : driver.incidentsCount * 100 + driver.harshEventsCount,
        cell: (driver) => <ActivityCell driver={driver} />,
    },
    {
        key: 'phone',
        header: 'Teléfono',
        width: 'w-40',
        cell: (driver) =>
            driver.phone ? (
                <a
                    href={`tel:${driver.phone.replace(/[^+\d]/g, '')}`}
                    onClick={(e) => e.stopPropagation()}
                    className="inline-flex items-center gap-1.5 font-mono text-2xs text-fg-2 tabular-nums hover:text-primary"
                >
                    <Phone size={11} aria-hidden="true" />
                    {driver.phone}
                </a>
            ) : (
                <CellEmpty />
            ),
    },
    {
        key: 'lastSeen',
        header: 'Visto',
        width: 'w-32',
        sortValue: (driver) =>
            driver.lastSeenAt ? Date.parse(driver.lastSeenAt) : null,
        cell: (driver) => <SeenCell iso={driver.lastSeenAt} />,
    },
];

interface DriversTableProps {
    rows: DriverRow[];
    onSelect: (id: number) => void;
    empty?: React.ReactNode;
    /**
     * Presencia de datos por columna en TODO el tenant (la calcula el
     * backend). Si llega, manda sobre la heurística por página: así la
     * columna no parpadea entre páginas con y sin datos.
     */
    presence?: DriverColumnPresence;
}

export function DriversTable({
    rows,
    onSelect,
    empty,
    presence,
}: DriversTableProps) {
    // Columnas sin un solo dato (asset/riesgo/teléfono/visto aún no
    // sincronizados) se ocultan en vez de pintar "—" en cada fila.
    const columns = useMemo(
        () =>
            COLUMNS.filter((column) => {
                if (column.key === 'asset') {
                    return (
                        presence?.asset ??
                        rows.some((d) => d.currentAsset !== null)
                    );
                }

                if (column.key === 'risk' || column.key === 'activity') {
                    return (
                        presence?.risk ?? rows.some((d) => d.riskScore !== null)
                    );
                }

                if (column.key === 'phone') {
                    return presence?.phone ?? rows.some((d) => d.phone);
                }

                if (column.key === 'lastSeen') {
                    return (
                        presence?.lastSeen ??
                        rows.some((d) => d.lastSeenAt !== null)
                    );
                }

                return true;
            }),
        [rows, presence],
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
