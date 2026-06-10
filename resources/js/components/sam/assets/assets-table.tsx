import { RelativeTime } from '@/components/sam/relative-time';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { AssetRow } from '@/types/assets';
import { AssetStatusBadge } from './asset-status-badge';

function minutesSince(iso: string): number {
    return Math.max(0, Math.floor((Date.now() - Date.parse(iso)) / 60000));
}

function DevicesCell({ devices }: { devices: AssetRow['devices'] }) {
    if (devices.length === 0) {
        return <span className="text-fg-3">—</span>;
    }

    const [first, ...rest] = devices;

    return (
        <span className="flex items-center gap-1.5">
            <span className="truncate font-mono text-[11px] text-fg-2">
                {first.deviceType}
            </span>
            {rest.length > 0 && (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <span className="rounded-full bg-surface-3 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-fg-2">
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
        return <span className="text-fg-3">—</span>;
    }

    if (location.formattedLocation) {
        return (
            <span className="truncate text-[12px] text-fg-2">
                {location.formattedLocation}
            </span>
        );
    }

    return (
        <span className="font-mono text-[11px] text-fg-2 tabular-nums">
            {location.latitude.toFixed(5)}, {location.longitude.toFixed(5)}
        </span>
    );
}

interface AssetsTableProps {
    rows: AssetRow[];
    onSelect: (id: number) => void;
}

export function AssetsTable({ rows, onSelect }: AssetsTableProps) {
    return (
        <div className="min-h-0 flex-1 overflow-auto">
            <table className="w-full border-collapse">
                <thead>
                    <tr className="sticky top-0 z-10 border-b border-border bg-surface-3 text-[10px] font-semibold tracking-[0.08em] text-fg-3 uppercase">
                        <th className="px-2.5 py-2 text-left">Activo</th>
                        <th className="w-32 px-2.5 py-2 text-left">Estado</th>
                        <th className="w-32 px-2.5 py-2 text-left">Tipo</th>
                        <th className="w-44 px-2.5 py-2 text-left">
                            Dispositivos
                        </th>
                        <th className="w-56 px-2.5 py-2 text-left">
                            Última posición
                        </th>
                        <th className="w-28 px-2.5 py-2 text-left">Visto</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((asset) => (
                        <tr
                            key={asset.id}
                            className="cursor-pointer border-b border-border transition-colors hover:bg-surface-2"
                            onClick={() => onSelect(asset.id)}
                        >
                            <td className="px-2.5 py-2.5">
                                <div className="flex flex-col">
                                    <span className="truncate text-[13px] font-medium text-fg-1">
                                        {asset.name}
                                    </span>
                                    {asset.code && (
                                        <span className="font-mono text-[10px] text-fg-3">
                                            {asset.code}
                                        </span>
                                    )}
                                </div>
                            </td>
                            <td className="px-2.5 py-2.5">
                                <AssetStatusBadge status={asset.status} />
                            </td>
                            <td className="px-2.5 py-2.5 text-[12px] text-fg-2">
                                {asset.type?.name ?? '—'}
                            </td>
                            <td className="px-2.5 py-2.5">
                                <DevicesCell devices={asset.devices} />
                            </td>
                            <td className="px-2.5 py-2.5">
                                <LocationCell location={asset.lastLocation} />
                            </td>
                            <td className="px-2.5 py-2.5">
                                {asset.lastSeenAt ? (
                                    <RelativeTime
                                        minutes={minutesSince(asset.lastSeenAt)}
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
