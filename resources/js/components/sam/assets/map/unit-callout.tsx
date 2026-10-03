import { Link } from '@inertiajs/react';
import { ArrowUpRight, User, X } from 'lucide-react';
import { speedLine } from '@/components/sam/map/markers';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import { relativeLabel } from '@/lib/time';
import assetRoutes from '@/routes/assets';
import type { AssetMarker } from '@/types/assets';
import { StatusDot } from './status-dot';

export function UnitCallout({
    asset,
    statusLabels,
    teamSlug,
    onClose,
}: {
    asset: AssetMarker;
    statusLabels: Record<string, string>;
    teamSlug: string | null;
    onClose: () => void;
}) {
    return (
        <div className="overflow-hidden rounded-lg border border-border bg-surface-1 shadow-lg">
            <div className="flex items-start gap-2 px-3 pt-3 pb-2">
                <StatusDot status={asset.status} className="mt-1.5" />
                <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-semibold text-fg-1">
                        {asset.name}
                    </div>
                    <div className="text-2xs text-fg-3">
                        {asset.code ? `${asset.code} · ` : ''}
                        {statusLabels[asset.status] ?? asset.status}
                    </div>
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Cerrar"
                    className="-m-1 grid size-6 cursor-pointer place-items-center rounded-sm text-fg-3 transition-colors hover:bg-surface-2 hover:text-fg-1 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <X className="size-3.5" />
                </button>
            </div>

            <dl className="grid grid-cols-2 gap-x-3 gap-y-2 border-t border-border px-3 py-2.5 text-xs">
                <div>
                    <dt className="text-2xs text-fg-3">Movimiento</dt>
                    <dd className="font-medium text-fg-1 tabular-nums">
                        {speedLine(asset)}
                    </dd>
                </div>
                <div>
                    <dt className="text-2xs text-fg-3">Última posición</dt>
                    <dd
                        className="font-medium text-fg-1"
                        title={formatDateTime(asset.recordedAt)}
                    >
                        {relativeLabel(asset.recordedAt)}
                    </dd>
                </div>
                <div className="col-span-2">
                    <dt className="text-2xs text-fg-3">Conductor</dt>
                    <dd className="flex items-center gap-1 font-medium text-fg-1">
                        <User className="size-3 text-fg-3" aria-hidden="true" />
                        {asset.driver ?? (
                            <span className="font-normal text-fg-3">
                                Sin asignar
                            </span>
                        )}
                    </dd>
                </div>
            </dl>

            <div className="flex items-center justify-between gap-2 border-t border-border bg-surface-2/60 px-3 py-2">
                <span className="font-mono text-2xs text-fg-3 tabular-nums">
                    {asset.latitude.toFixed(4)}, {asset.longitude.toFixed(4)}
                </span>
                {teamSlug && (
                    <Button size="sm" asChild>
                        <Link
                            href={assetRoutes.show([teamSlug, asset.id])}
                            prefetch
                        >
                            Ver unidad
                            <ArrowUpRight className="size-3.5" />
                        </Link>
                    </Button>
                )}
            </div>
        </div>
    );
}
