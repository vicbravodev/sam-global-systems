import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Camera,
    ChevronLeft,
    Cpu,
    Flame,
    Gauge,
    History,
    Key,
    Map as MapIcon,
    MapPin,
    Navigation,
    Route,
    Thermometer,
    Truck,
    User,
    Zap,
    BatteryMedium,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useRef } from 'react';
import { AssetSignal } from '@/components/sam/assets/asset-signal';
import { AssetStatusBadge } from '@/components/sam/assets/asset-status-badge';
import { PlateChip, vehicleTitle } from '@/components/sam/assets/vehicle-line';
import { EntityAvatar } from '@/components/sam/entity-avatar';
import { LinkedIncidentsCard } from '@/components/sam/linked-incidents-card';
import { PointMap } from '@/components/sam/point-map';
import type { PointTone } from '@/components/sam/point-map';
import { RecentEventsCard } from '@/components/sam/recent-events-card';
import { RelativeTime } from '@/components/sam/relative-time';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TEAM_BROADCAST_EVENT_NAME } from '@/hooks/use-team-broadcasts';
import type { TeamBroadcastDetail } from '@/hooks/use-team-broadcasts';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import {
    assetTypeLabel,
    connectivityLabel,
    CONNECTIVITY_LABELS,
    sourceLabel,
} from '@/lib/labels';
import { isFresh, minutesSince } from '@/lib/time';
import { cn } from '@/lib/utils';
import type {
    AssetShowProps,
    AssetStatusValue,
    LocationHistoryEntry,
    TelemetryEntry,
} from '@/types/assets';

const RELOAD_DEBOUNCE_MS = 2000;

const MOVING_SPEED_KPH = 5;

const STATUS_TONE: Record<AssetStatusValue, PointTone> = {
    active: 'ok',
    inactive: 'neutral',
    offline: 'neutral',
    alert: 'high',
    critical: 'critical',
    maintenance: 'warn',
};

// Engine state arrives verbatim from the provider so the stored reading stays
// faithful to the source; translating it is a presentation concern.
const ENGINE_STATE_LABELS: Record<string, string> = {
    On: 'Encendido',
    Off: 'Apagado',
    Idle: 'Ralentí',
};

const TELEMETRY_ICONS: Record<string, LucideIcon> = {
    speed: Gauge,
    fuel: Flame,
    temperature: Thermometer,
    camera_status: Camera,
    battery: BatteryMedium,
    ignition: Key,
    odometer: Route,
};

const UNIT_LABELS: Record<string, string> = {
    percent: '%',
    celsius: '°C',
    km: 'km',
    'km/h': 'km/h',
    volts: 'V',
};

const DEVICE_ICONS: Record<string, LucideIcon> = {
    gateway: Cpu,
    camera: Camera,
    dashcam: Camera,
    gps_tracker: MapPin,
};

function telemetryValue(data: TelemetryEntry['data']): {
    value: string;
    unit: string | null;
} {
    if (data === null) {
        return { value: '—', unit: null };
    }

    const raw = data.value;
    const unit =
        typeof data.unit === 'string'
            ? (UNIT_LABELS[data.unit] ?? data.unit)
            : null;

    if (typeof raw === 'string') {
        return {
            value:
                ENGINE_STATE_LABELS[raw] ??
                (raw in CONNECTIVITY_LABELS ? connectivityLabel(raw) : raw),
            unit,
        };
    }

    if (typeof raw === 'number') {
        return {
            value: formatNumber(raw, { maximumFractionDigits: 1 }),
            unit,
        };
    }

    return { value: JSON.stringify(data), unit: null };
}

// ---- Header ----

function AssetHero({
    asset,
    teamSlug,
}: {
    asset: AssetShowProps['asset'];
    teamSlug: string | null;
}) {
    const title = vehicleTitle(asset.vehicle);

    return (
        <header className="flex flex-wrap items-start justify-between gap-4">
            <div className="flex min-w-0 items-start gap-3">
                <Button variant="ghost" size="sm" asChild className="mt-1">
                    <Link
                        href={teamSlug ? `/${teamSlug}/assets` : '#'}
                        aria-label="Volver a la flota"
                    >
                        <ChevronLeft size={15} />
                    </Link>
                </Button>
                <EntityAvatar
                    name={asset.name}
                    size={52}
                    shape="square"
                    icon={Truck}
                />
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2.5">
                        <h1 className="sam-h1 truncate">{asset.name}</h1>
                        <AssetStatusBadge status={asset.status} />
                        {asset.vehicle?.plate && (
                            <PlateChip plate={asset.vehicle.plate} />
                        )}
                    </div>
                    <p className="sam-meta mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                        {asset.code && (
                            <span className="font-mono">{asset.code}</span>
                        )}
                        {title && <span className="text-fg-2">{title}</span>}
                        {asset.type && (
                            <span>
                                {assetTypeLabel(
                                    asset.type.code,
                                    asset.type.name,
                                )}
                            </span>
                        )}
                        {asset.provider && (
                            <span>
                                vía{' '}
                                <span className="text-fg-2">
                                    {asset.provider}
                                </span>
                            </span>
                        )}
                        {asset.vehicle?.vin && (
                            <span className="font-mono text-3xs">
                                VIN {asset.vehicle.vin}
                            </span>
                        )}
                    </p>
                </div>
            </div>

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
        </header>
    );
}

// ---- "Ahora" strip ----

function NowTile({
    icon: Icon,
    label,
    value,
    unit,
    recordedAt,
    tone = 'neutral',
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    unit?: string | null;
    recordedAt: string | null;
    tone?: 'neutral' | 'ok' | 'warn' | 'critical';
}) {
    const stale = recordedAt !== null && !isFresh(recordedAt);

    return (
        <div className="flex min-w-0 flex-col gap-0.5 bg-surface-1 px-4 py-3">
            <span className="flex items-center gap-1.5 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                <Icon
                    className="size-3"
                    strokeWidth={1.75}
                    aria-hidden="true"
                />
                <span className="truncate">{label}</span>
            </span>
            <span
                className={cn(
                    'flex items-baseline gap-1 font-sans text-xl leading-tight font-semibold tracking-tight',
                    tone === 'ok' && 'text-severity-low',
                    tone === 'warn' && 'text-severity-medium',
                    tone === 'critical' && 'text-severity-critical',
                    tone === 'neutral' && 'text-fg-1',
                    stale && 'text-fg-3',
                )}
            >
                {value}
                {unit && (
                    <span className="text-xs font-normal text-fg-3">
                        {unit}
                    </span>
                )}
            </span>
            {recordedAt && (
                <span
                    className="text-2xs text-fg-3"
                    title={formatDateTime(recordedAt)}
                >
                    <RelativeTime minutes={minutesSince(recordedAt)} />
                </span>
            )}
        </div>
    );
}

function NowStrip({
    asset,
    telemetry,
}: {
    asset: AssetShowProps['asset'];
    telemetry: TelemetryEntry[];
}) {
    const location = asset.lastLocation;
    const byType = useMemo(
        () => new Map(telemetry.map((entry) => [entry.type, entry])),
        [telemetry],
    );

    const tiles: React.ReactNode[] = [];

    if (location?.speed !== null && location?.speed !== undefined) {
        const fresh = isFresh(location.recordedAt);
        const moving = fresh && location.speed > MOVING_SPEED_KPH;

        tiles.push(
            <NowTile
                key="speed"
                icon={moving ? Navigation : Gauge}
                label={moving ? 'En ruta' : fresh ? 'Detenida' : 'Velocidad'}
                value={formatNumber(location.speed, {
                    maximumFractionDigits: 0,
                })}
                unit="km/h"
                recordedAt={location.recordedAt}
                tone={moving ? 'ok' : 'neutral'}
            />,
        );
    }

    (
        [
            'ignition',
            'fuel',
            'odometer',
            'temperature',
            'battery',
            'camera_status',
        ] as const
    ).forEach((type) => {
        const entry = byType.get(type);

        if (!entry) {
            return;
        }

        const { value, unit } = telemetryValue(entry.data);
        const numeric =
            typeof entry.data?.value === 'number' ? entry.data.value : null;
        const tone =
            type === 'fuel' && numeric !== null && numeric < 15
                ? 'critical'
                : type === 'fuel' && numeric !== null && numeric < 30
                  ? 'warn'
                  : type === 'ignition' && value === 'Encendido'
                    ? 'ok'
                    : 'neutral';

        tiles.push(
            <NowTile
                key={type}
                icon={TELEMETRY_ICONS[type] ?? Zap}
                label={entry.label}
                value={value}
                unit={unit}
                recordedAt={entry.recordedAt}
                tone={tone}
            />,
        );
    });

    if (tiles.length === 0) {
        return null;
    }

    return (
        <div className="scrollbar-none overflow-x-auto rounded-lg border border-border bg-border">
            <div className="grid auto-cols-[minmax(140px,1fr)] grid-flow-col gap-px">
                {tiles}
            </div>
        </div>
    );
}

// ---- Cards ----

function LocationCard({
    asset,
    history,
}: {
    asset: AssetShowProps['asset'];
    history: LocationHistoryEntry[];
}) {
    const location = asset.lastLocation;
    // Oldest first so the trail draws toward the current position.
    const trail = useMemo(
        () =>
            [...history].reverse().map((entry) => ({
                latitude: entry.latitude,
                longitude: entry.longitude,
            })),
        [history],
    );

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

function DriverCard({
    driver,
    teamSlug,
}: {
    driver: AssetShowProps['asset']['driver'];
    teamSlug: string | null;
}) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <User size={15} /> Conductor
                </CardTitle>
            </CardHeader>
            <CardContent className="p-4">
                {driver === null ? (
                    <p className="text-sm text-fg-3">
                        Sin conductor asignado ahora mismo. La asignación llega
                        del proveedor (Samsara) o se registra a mano.
                    </p>
                ) : (
                    <Link
                        href={
                            teamSlug ? `/${teamSlug}/drivers/${driver.id}` : '#'
                        }
                        className="flex items-center gap-3 rounded-md border border-border bg-surface-2 p-3 transition-colors hover:border-primary/40"
                    >
                        <EntityAvatar name={driver.name} size={40} />
                        <span className="flex min-w-0 flex-col">
                            <span className="truncate text-sm font-medium text-fg-1">
                                {driver.name}
                            </span>
                            <span className="font-mono text-2xs text-fg-3">
                                {driver.employeeCode ?? 'conductor principal'}
                            </span>
                        </span>
                    </Link>
                )}
            </CardContent>
        </Card>
    );
}

function VehicleCard({ asset }: { asset: AssetShowProps['asset'] }) {
    const vehicle = asset.vehicle;
    const rows: [string, React.ReactNode][] = [
        ['Marca', vehicle?.make ?? null],
        ['Modelo', vehicle?.model ?? null],
        ['Año', vehicle?.year ?? null],
        ['Placa', vehicle?.plate ? <PlateChip plate={vehicle.plate} /> : null],
        [
            'VIN',
            vehicle?.vin ? (
                <span className="font-mono text-xs">{vehicle.vin}</span>
            ) : null,
        ],
        [
            'Tipo',
            asset.type
                ? assetTypeLabel(asset.type.code, asset.type.name)
                : null,
        ],
        ['Proveedor', asset.provider ?? null],
        [
            'ID en proveedor',
            asset.externalPrimaryId ? (
                <span className="font-mono text-xs">
                    {asset.externalPrimaryId}
                </span>
            ) : null,
        ],
        ['Integración', asset.sourceIntegration ?? null],
        [
            'Alta en SAM',
            asset.firstSeenAt ? formatDate(asset.firstSeenAt) : null,
        ],
    ];
    const known = rows.filter(([, value]) => value !== null && value !== '');

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <Truck size={15} /> Vehículo
                </CardTitle>
                {asset.devices.length > 0 && (
                    <span className="flex items-center gap-1">
                        {asset.devices.map((device) => {
                            const Icon = DEVICE_ICONS[device.deviceType] ?? Cpu;

                            return (
                                <span
                                    key={device.id}
                                    title={`${device.label}${device.externalDeviceId ? ` · ${device.externalDeviceId}` : ''}`}
                                    className="inline-flex items-center gap-1 rounded-sm border border-border bg-surface-2 px-1.5 py-0.5 font-mono text-3xs text-fg-2"
                                >
                                    <Icon size={11} strokeWidth={1.75} />
                                    {device.label}
                                </span>
                            );
                        })}
                    </span>
                )}
            </CardHeader>
            <CardContent className="p-4">
                {known.length === 0 ? (
                    <p className="text-sm text-fg-3">
                        Sin datos del vehículo todavía. Marca, modelo, placa y
                        VIN llegan con la sincronización del proveedor.
                    </p>
                ) : (
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
                        {known.map(([label, value]) => (
                            <div key={label} className="flex flex-col gap-0.5">
                                <dt className="text-2xs tracking-caps text-fg-3 uppercase">
                                    {label}
                                </dt>
                                <dd className="text-sm text-fg-1">{value}</dd>
                            </div>
                        ))}
                    </dl>
                )}
            </CardContent>
        </Card>
    );
}

function TelemetryCard({ telemetry }: { telemetry: TelemetryEntry[] }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <Zap size={15} /> Telemetría
                </CardTitle>
                <span className="sam-meta">
                    {telemetry.length}{' '}
                    {telemetry.length === 1 ? 'medidor' : 'medidores'}
                </span>
            </CardHeader>
            <CardContent className="p-0">
                {telemetry.length === 0 ? (
                    <p className="px-4 py-5 text-sm text-fg-3">
                        Esta unidad aún no reporta telemetría (velocidad,
                        odómetro, combustible). Aparecerá en cuanto el equipo
                        empiece a transmitir.
                    </p>
                ) : (
                    <ul className="divide-y divide-border">
                        {telemetry.map((entry) => {
                            const Icon = TELEMETRY_ICONS[entry.type] ?? Zap;
                            const { value, unit } = telemetryValue(entry.data);

                            return (
                                <li
                                    key={entry.type}
                                    className="flex items-center gap-3 px-4 py-2.5"
                                >
                                    <Icon
                                        size={13}
                                        strokeWidth={1.75}
                                        className="shrink-0 text-fg-3"
                                        aria-hidden="true"
                                    />
                                    <span className="w-32 shrink-0 text-xs text-fg-2">
                                        {entry.label}
                                    </span>
                                    <span className="flex-1 font-mono text-sm text-fg-1 tabular-nums">
                                        {value}
                                        {unit && (
                                            <span className="ml-1 text-2xs text-fg-3">
                                                {unit}
                                            </span>
                                        )}
                                    </span>
                                    <span
                                        title={formatDateTime(entry.recordedAt)}
                                    >
                                        <RelativeTime
                                            minutes={minutesSince(
                                                entry.recordedAt,
                                            )}
                                        />
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function LocationHistoryCard({ history }: { history: LocationHistoryEntry[] }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <History size={15} /> Recorrido reciente
                </CardTitle>
                <span className="sam-meta">
                    últimas {history.length}{' '}
                    {history.length === 1 ? 'posición' : 'posiciones'}
                </span>
            </CardHeader>
            <CardContent className="p-0">
                {history.length === 0 ? (
                    <p className="px-4 py-5 text-sm text-fg-3">
                        El recorrido se llenará en cuanto la unidad empiece a
                        reportar ubicación.
                    </p>
                ) : (
                    <div className="max-h-80 overflow-auto">
                        <table className="w-full border-collapse">
                            <thead>
                                <tr className="sticky top-0 z-10 border-b border-border bg-surface-3 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                                    <th className="w-32 px-4 py-2 text-left">
                                        Cuándo
                                    </th>
                                    <th className="px-2.5 py-2 text-left">
                                        Ubicación
                                    </th>
                                    <th className="w-28 px-2.5 py-2 text-right">
                                        Velocidad
                                    </th>
                                    <th className="w-20 px-2.5 py-2 text-right">
                                        Rumbo
                                    </th>
                                    <th className="w-24 px-2.5 py-2 text-left">
                                        Fuente
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {history.map((entry) => (
                                    <tr
                                        key={entry.id}
                                        className="border-b border-border"
                                    >
                                        <td
                                            className="px-4 py-2"
                                            title={formatDateTime(
                                                entry.recordedAt,
                                            )}
                                        >
                                            <RelativeTime
                                                minutes={minutesSince(
                                                    entry.recordedAt,
                                                )}
                                            />
                                        </td>
                                        <td className="px-2.5 py-2">
                                            {entry.formattedLocation ? (
                                                <span className="text-xs text-fg-2">
                                                    {entry.formattedLocation}
                                                </span>
                                            ) : (
                                                <span className="font-mono text-2xs text-fg-2 tabular-nums">
                                                    {entry.latitude.toFixed(5)},{' '}
                                                    {entry.longitude.toFixed(5)}
                                                </span>
                                            )}
                                        </td>
                                        <td
                                            className={cn(
                                                'px-2.5 py-2 text-right font-mono text-2xs tabular-nums',
                                                entry.speed !== null &&
                                                    entry.speed >
                                                        MOVING_SPEED_KPH
                                                    ? 'text-fg-1'
                                                    : 'text-fg-3',
                                            )}
                                        >
                                            {entry.speed !== null
                                                ? `${formatNumber(entry.speed, { maximumFractionDigits: 0 })} km/h`
                                                : '—'}
                                        </td>
                                        <td className="px-2.5 py-2 text-right font-mono text-2xs text-fg-2 tabular-nums">
                                            {entry.heading !== null
                                                ? `${entry.heading}°`
                                                : '—'}
                                        </td>
                                        <td className="px-2.5 py-2 font-mono text-3xs text-fg-3">
                                            {sourceLabel(entry.source)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

// ---- Main page ----

export default function AssetShow() {
    const page = usePage();
    const { asset, telemetry, locationHistory, incidents, recentEvents } =
        page.props as unknown as AssetShowProps;
    const teamSlug = page.props.currentTeam?.slug ?? null;

    // Live updates for THIS asset only: location polls refresh position +
    // history, status transitions refresh the header badge. Bursts coalesce
    // into one partial reload with the union of affected props.
    const pendingKeys = useRef<Set<string>>(new Set());
    const timer = useRef<number | null>(null);

    useEffect(() => {
        const handler = (event: Event) => {
            const detail = (event as CustomEvent<TeamBroadcastDetail>).detail;
            const payload = detail?.payload as
                | { asset_id?: number }
                | undefined;

            if (payload?.asset_id !== asset.id) {
                return;
            }

            if (detail?.event === 'asset.location_updated') {
                pendingKeys.current.add('asset');
                pendingKeys.current.add('locationHistory');
                pendingKeys.current.add('telemetry');
            } else if (detail?.event === 'asset.status_changed') {
                pendingKeys.current.add('asset');
            } else {
                return;
            }

            if (timer.current !== null) {
                return;
            }

            timer.current = window.setTimeout(() => {
                const only = [...pendingKeys.current];
                pendingKeys.current.clear();
                timer.current = null;
                router.reload({ only });
            }, RELOAD_DEBOUNCE_MS);
        };

        window.addEventListener(TEAM_BROADCAST_EVENT_NAME, handler);

        return () => {
            window.removeEventListener(TEAM_BROADCAST_EVENT_NAME, handler);

            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        };
    }, [asset.id]);

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
                        <LocationCard asset={asset} history={locationHistory} />
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
                        <LocationHistoryCard history={locationHistory} />
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

AssetShow.layout = (props: {
    currentTeam?: { slug: string } | null;
    asset?: { id: number; name: string } | null;
}) => ({
    breadcrumbs: [
        {
            title: 'Flota',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/assets`
                : '/assets',
        },
        ...(props.asset
            ? [
                  {
                      title: props.asset.name,
                      href: props.currentTeam
                          ? `/${props.currentTeam.slug}/assets/${props.asset.id}`
                          : '#',
                  },
              ]
            : []),
    ],
});
