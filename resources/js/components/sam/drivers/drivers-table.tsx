import { RelativeTime } from '@/components/sam/relative-time';
import { cn } from '@/lib/utils';
import type { DriverRow } from '@/types/drivers';
import { DriverStatusBadge } from './driver-status-badge';

function minutesSince(iso: string): number {
    return Math.max(0, Math.floor((Date.now() - Date.parse(iso)) / 60000));
}

function AssetCell({ asset }: { asset: DriverRow['currentAsset'] }) {
    if (asset === null) {
        return <span className="text-fg-3">—</span>;
    }

    return (
        <div className="flex flex-col">
            <span className="truncate text-[12px] text-fg-2">{asset.name}</span>
            {asset.code && (
                <span className="font-mono text-[10px] text-fg-3">
                    {asset.code}
                </span>
            )}
        </div>
    );
}

function RiskCell({ score }: { score: number | null }) {
    if (score === null) {
        return <span className="text-fg-3">—</span>;
    }

    return (
        <span
            className={cn(
                'font-mono text-[12px] font-semibold tabular-nums',
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

interface DriversTableProps {
    rows: DriverRow[];
}

export function DriversTable({ rows }: DriversTableProps) {
    return (
        <div className="min-h-0 flex-1 overflow-auto">
            <table className="w-full border-collapse">
                <thead>
                    <tr className="sticky top-0 z-10 border-b border-border bg-surface-3 text-[10px] font-semibold tracking-[0.08em] text-fg-3 uppercase">
                        <th className="px-2.5 py-2 text-left">Conductor</th>
                        <th className="w-36 px-2.5 py-2 text-left">Estado</th>
                        <th className="w-48 px-2.5 py-2 text-left">
                            Asset asignado
                        </th>
                        <th className="w-24 px-2.5 py-2 text-left">Riesgo</th>
                        <th className="w-40 px-2.5 py-2 text-left">Teléfono</th>
                        <th className="w-28 px-2.5 py-2 text-left">Visto</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((driver) => (
                        <tr
                            key={driver.id}
                            className="border-b border-border transition-colors hover:bg-surface-2"
                        >
                            <td className="px-2.5 py-2.5">
                                <div className="flex flex-col">
                                    <span className="truncate text-[13px] font-medium text-fg-1">
                                        {driver.fullName}
                                    </span>
                                    {driver.employeeCode && (
                                        <span className="font-mono text-[10px] text-fg-3">
                                            {driver.employeeCode}
                                        </span>
                                    )}
                                </div>
                            </td>
                            <td className="px-2.5 py-2.5">
                                <DriverStatusBadge status={driver.status} />
                            </td>
                            <td className="px-2.5 py-2.5">
                                <AssetCell asset={driver.currentAsset} />
                            </td>
                            <td className="px-2.5 py-2.5">
                                <RiskCell score={driver.riskScore} />
                            </td>
                            <td className="px-2.5 py-2.5">
                                {driver.phone ? (
                                    <span className="font-mono text-[11px] text-fg-2 tabular-nums">
                                        {driver.phone}
                                    </span>
                                ) : (
                                    <span className="text-fg-3">—</span>
                                )}
                            </td>
                            <td className="px-2.5 py-2.5">
                                {driver.lastSeenAt ? (
                                    <RelativeTime
                                        minutes={minutesSince(
                                            driver.lastSeenAt,
                                        )}
                                    />
                                ) : (
                                    <span className="text-fg-3">—</span>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
