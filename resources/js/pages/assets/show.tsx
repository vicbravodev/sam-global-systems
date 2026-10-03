import type { SharedPageProps } from '@inertiajs/core';
import { Head, usePage } from '@inertiajs/react';
import { AssetHero } from '@/components/sam/assets/detail/asset-hero';
import { DriverCard } from '@/components/sam/assets/detail/driver-card';
import { LocationCard } from '@/components/sam/assets/detail/location-card';
import { LocationHistoryCard } from '@/components/sam/assets/detail/location-history-card';
import { NowStrip } from '@/components/sam/assets/detail/now-strip';
import { TelemetryCard } from '@/components/sam/assets/detail/telemetry-card';
import { useLiveAsset } from '@/components/sam/assets/detail/use-live-asset';
import { VehicleCard } from '@/components/sam/assets/detail/vehicle-card';
import { LinkedIncidentsCard } from '@/components/sam/linked-incidents-card';
import { RecentEventsCard } from '@/components/sam/recent-events-card';
import assetRoutes from '@/routes/assets';
import type { AssetShowProps } from '@/types/assets';

export default function AssetShow({
    asset: serverAsset,
    telemetry,
    locationHistory,
    locationTrail,
    trailWindowHours,
    incidents,
    recentEvents,
}: AssetShowProps) {
    const page = usePage();

    const { asset, trail } = useLiveAsset(
        serverAsset,
        locationTrail,
        trailWindowHours,
    );
    const teamSlug = page.props.currentTeam?.slug ?? null;

    return (
        <>
            <Head title={`${asset.name} - Flota`} />
            <div className="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 md:p-6">
                <AssetHero asset={asset} teamSlug={teamSlug} />

                <NowStrip asset={asset} telemetry={telemetry} />

                {/* Operación a la izquierda (mapa, actividad, incidentes,
                    recorrido); ficha a la derecha (conductor, vehículo,
                    telemetría). */}
                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                    <div className="flex min-w-0 flex-col gap-4">
                        <LocationCard asset={asset} trail={trail} />
                        <RecentEventsCard
                            events={recentEvents ?? []}
                            teamSlug={teamSlug}
                            subject="Esta unidad"
                        />
                        <LinkedIncidentsCard
                            incidents={incidents}
                            teamSlug={teamSlug}
                            subject="Esta unidad"
                        />
                        <LocationHistoryCard
                            history={locationHistory}
                            windowHours={trailWindowHours ?? 2}
                        />
                    </div>
                    <div className="flex min-w-0 flex-col gap-4">
                        <DriverCard driver={asset.driver} teamSlug={teamSlug} />
                        <VehicleCard asset={asset} />
                        <TelemetryCard telemetry={telemetry} />
                    </div>
                </div>
            </div>
        </>
    );
}

AssetShow.layout = (props: SharedPageProps & Partial<AssetShowProps>) => ({
    breadcrumbs: [
        {
            title: 'Flota',
            href: props.currentTeam
                ? assetRoutes.index.url(props.currentTeam.slug)
                : '#',
        },
        ...(props.asset
            ? [
                  {
                      title: props.asset.name,
                      href: props.currentTeam
                          ? assetRoutes.show.url([
                                props.currentTeam.slug,
                                props.asset.id,
                            ])
                          : '#',
                  },
              ]
            : []),
    ],
});
