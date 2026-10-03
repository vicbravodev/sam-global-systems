import { Link } from '@inertiajs/react';
import { Map as MapIcon, Truck, User } from 'lucide-react';
import { AssetSignal } from '@/components/sam/assets/asset-signal';
import { AssetStatusBadge } from '@/components/sam/assets/asset-status-badge';
import { MonitoringSwitch } from '@/components/sam/assets/monitoring-switch';
import { PlateChip, vehicleTitle } from '@/components/sam/assets/vehicle-line';
import { DetailHeader } from '@/components/sam/detail-header';
import { EntityAvatar } from '@/components/sam/entity-avatar';
import { Button } from '@/components/ui/button';
import { assetTypeLabel } from '@/lib/labels';
import type { AssetShowProps } from '@/types/assets';

export function AssetHero({
    asset,
    teamSlug,
}: {
    asset: AssetShowProps['asset'];
    teamSlug: string | null;
}) {
    const title = vehicleTitle(asset.vehicle);

    return (
        <DetailHeader
            backHref={teamSlug ? `/${teamSlug}/assets` : '#'}
            backLabel="Volver a la flota"
            media={
                <EntityAvatar
                    name={asset.name}
                    size={52}
                    shape="square"
                    icon={Truck}
                />
            }
            title={asset.name}
            badges={
                <>
                    <AssetStatusBadge status={asset.status} />
                    {asset.vehicle?.plate && (
                        <PlateChip plate={asset.vehicle.plate} />
                    )}
                    <MonitoringSwitch
                        assetId={asset.id}
                        assetName={asset.name}
                        state={asset.monitoringState}
                        teamSlug={teamSlug}
                        withLabel
                        className="ml-1"
                    />
                </>
            }
            meta={
                <>
                    {asset.code && (
                        <span className="font-mono">{asset.code}</span>
                    )}
                    {title && <span className="text-fg-2">{title}</span>}
                    {asset.type && (
                        <span>
                            {assetTypeLabel(asset.type.code, asset.type.name)}
                        </span>
                    )}
                    {asset.provider && (
                        <span>
                            vía{' '}
                            <span className="text-fg-2">{asset.provider}</span>
                        </span>
                    )}
                    {asset.vehicle?.vin && (
                        <span className="font-mono text-3xs">
                            VIN {asset.vehicle.vin}
                        </span>
                    )}
                </>
            }
            actions={
                <div className="flex flex-col items-end gap-2">
                    <span className="sam-meta">
                        <AssetSignal
                            lastSignalAt={asset.lastSignalAt}
                            hasDevice={asset.devices.length > 0}
                            withPrefix
                        />
                    </span>
                    <div className="flex flex-wrap items-center gap-2">
                        {teamSlug && asset.lastLocation && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/${teamSlug}/assets/map`}>
                                    <MapIcon size={13} />
                                    Ver en el mapa
                                </Link>
                            </Button>
                        )}
                        {teamSlug && asset.driver && (
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={`/${teamSlug}/drivers/${asset.driver.id}`}
                                >
                                    <User size={13} />
                                    Ver conductor
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>
            }
        />
    );
}
