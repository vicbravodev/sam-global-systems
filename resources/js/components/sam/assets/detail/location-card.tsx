import { MapPin } from 'lucide-react';
import { PointMap } from '@/components/sam/lazy-point-map';
import type { PointTone } from '@/components/sam/point-map';
import { RelativeTime } from '@/components/sam/relative-time';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import { minutesSince } from '@/lib/time';
import type {
    AssetShowProps,
    AssetStatusValue,
    LocationTrailPoint,
} from '@/types/assets';

const STATUS_TONE: Record<AssetStatusValue, PointTone> = {
    active: 'ok',
    inactive: 'neutral',
    offline: 'neutral',
    alert: 'high',
    critical: 'critical',
    maintenance: 'warn',
};

export function LocationCard({
    asset,
    trail,
}: {
    asset: AssetShowProps['asset'];
    /** Oldest first, so the line draws toward the current position. */
    trail: LocationTrailPoint[];
}) {
    const location = asset.lastLocation;

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <MapPin size={15} /> Posición
                </CardTitle>
                {location && (
                    <span
                        className="sam-meta"
                        title={formatDateTime(location.recordedAt)}
                    >
                        <RelativeTime
                            minutes={minutesSince(location.recordedAt)}
                        />
                    </span>
                )}
            </CardHeader>
            <CardContent className="p-0">
                {location === null ? (
                    <p className="px-4 py-6 text-sm text-fg-3">
                        Esta unidad aún no reporta posición. Verifica que el
                        equipo GPS esté instalado y la integración
                        sincronizando.
                    </p>
                ) : (
                    <>
                        <div className="h-64 border-b border-border">
                            <PointMap
                                latitude={location.latitude}
                                longitude={location.longitude}
                                heading={location.heading}
                                speed={
                                    asset.currentSpeed &&
                                    !asset.currentSpeed.stale
                                        ? asset.currentSpeed.kph
                                        : 0
                                }
                                label={asset.name}
                                tone={STATUS_TONE[asset.status]}
                                trail={trail}
                            />
                        </div>
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                            <span className="text-sm text-fg-1">
                                {location.formattedLocation ??
                                    'Sin geocodificar'}
                            </span>
                            <span className="font-mono text-2xs text-fg-3 tabular-nums">
                                {location.latitude.toFixed(5)},{' '}
                                {location.longitude.toFixed(5)}
                            </span>
                            {location.heading !== null && (
                                <span className="font-mono text-2xs text-fg-3 tabular-nums">
                                    rumbo {location.heading}°
                                </span>
                            )}
                            <a
                                href={`https://www.google.com/maps?q=${location.latitude},${location.longitude}`}
                                target="_blank"
                                rel="noreferrer"
                                className="ml-auto text-2xs text-primary hover:underline"
                            >
                                Abrir en Google Maps
                            </a>
                        </div>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
