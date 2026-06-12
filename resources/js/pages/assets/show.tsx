import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, MapPin, Truck } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { AssetStatusBadge } from '@/components/sam/assets/asset-status-badge';
import { RelativeTime } from '@/components/sam/relative-time';
import { SeverityBadge } from '@/components/sam/severity-badge';
import type { Severity } from '@/components/sam/severity-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TEAM_BROADCAST_EVENT_NAME } from '@/hooks/use-team-broadcasts';
import type { TeamBroadcastDetail } from '@/hooks/use-team-broadcasts';
import type {
    AssetShowProps,
    LinkedIncident,
    LocationHistoryEntry,
    TelemetryEntry,
} from '@/types/assets';

const RELOAD_DEBOUNCE_MS = 2000;

const SEVERITY_LEVELS: Severity[] = [
    'critical',
    'high',
    'medium',
    'low',
    'info',
];

function toSeverity(code: string | undefined): Severity {
    return SEVERITY_LEVELS.includes(code as Severity)
        ? (code as Severity)
        : 'info';
}

function minutesSince(iso: string): number {
    return Math.max(0, Math.floor((Date.now() - Date.parse(iso)) / 60000));
}

function formatTelemetryValue(data: TelemetryEntry['data']): string {
    if (data === null) {
        return '—';
    }

    const value = data.value;
    const unit = typeof data.unit === 'string' ? ` ${data.unit}` : '';

    if (typeof value === 'number' || typeof value === 'string') {
        return `${typeof value === 'number' ? value.toLocaleString('es') : value}${unit}`;
    }

    return JSON.stringify(data);
}

// ---- Cards ----

function LastLocationCard({
    location,
}: {
    location: AssetShowProps['asset']['lastLocation'];
}) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <MapPin size={15} /> Última posición
                </CardTitle>
                {location && (
                    <RelativeTime minutes={minutesSince(location.recordedAt)} />
                )}
            </CardHeader>
            <CardContent className="p-4">
                {location === null ? (
                    <p className="text-sm text-fg-3">
                        Este activo aún no reporta posición. Verifica que el
                        dispositivo GPS esté instalado y la integración
                        sincronizando.
                    </p>
                ) : (
                    <div className="flex flex-col gap-2">
                        <div className="text-sm text-fg-1">
                            {location.formattedLocation ?? 'Sin geocodificar'}
                        </div>
                        <div className="font-mono text-[12px] text-fg-2 tabular-nums">
                            {location.latitude.toFixed(5)},{' '}
                            {location.longitude.toFixed(5)}
                        </div>
                        <div className="flex items-center gap-3 font-mono text-[11px] text-fg-3 tabular-nums">
                            {location.speed !== null && (
                                <span>
                                    {location.speed.toLocaleString('es')} km/h
                                </span>
                            )}
                            {location.heading !== null && (
                                <span>rumbo {location.heading}°</span>
                            )}
                        </div>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function TelemetryCard({ telemetry }: { telemetry: TelemetryEntry[] }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0">Telemetría</CardTitle>
                <span className="sam-meta">
                    {telemetry.length}{' '}
                    {telemetry.length === 1 ? 'medidor' : 'medidores'}
                </span>
            </CardHeader>
            <CardContent className="p-0">
                {telemetry.length === 0 ? (
                    <p className="px-4 py-6 text-sm text-fg-3">
                        Este activo aún no reporta telemetría (velocidad,
                        odómetro, combustible). Aparecerá automáticamente en
                        cuanto el dispositivo empiece a transmitir.
                    </p>
                ) : (
                    <ul className="divide-y divide-border">
                        {telemetry.map((entry) => (
                            <li
                                key={entry.type}
                                className="flex items-center gap-3 px-4 py-2.5"
                            >
                                <span className="w-36 shrink-0 text-[12px] text-fg-2">
                                    {entry.label}
                                </span>
                                <span className="flex-1 font-mono text-[13px] text-fg-1 tabular-nums">
                                    {formatTelemetryValue(entry.data)}
                                </span>
                                <RelativeTime
                                    minutes={minutesSince(entry.recordedAt)}
                                />
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function IncidentsCard({
    incidents,
    teamSlug,
}: {
    incidents: LinkedIncident[];
    teamSlug: string | null;
}) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0">
                    Incidentes vinculados
                </CardTitle>
                {teamSlug && incidents.length > 0 && (
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={`/${teamSlug}/incidents`}>
                            Ver bandeja
                            <ChevronRight />
                        </Link>
                    </Button>
                )}
            </CardHeader>
            <CardContent className="p-0">
                {incidents.length === 0 ? (
                    <p className="px-4 py-6 text-sm text-fg-3">
                        Este activo no ha generado incidentes.
                    </p>
                ) : (
                    <ul className="divide-y divide-border">
                        {incidents.map((incident) => (
                            <li key={incident.id}>
                                <Link
                                    href={
                                        teamSlug
                                            ? `/${teamSlug}/incidents`
                                            : '#'
                                    }
                                    className="flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-surface-2"
                                >
                                    <SeverityBadge
                                        level={toSeverity(
                                            incident.priority?.code,
                                        )}
                                    />
                                    <span className="flex-1 truncate text-sm text-fg-1">
                                        {incident.title}
                                    </span>
                                    <span className="rounded-sm border border-border bg-surface-3 px-1.5 py-0.5 text-[10px] font-semibold whitespace-nowrap text-fg-3">
                                        {incident.status?.name ?? '—'}
                                    </span>
                                    {incident.openedAt && (
                                        <RelativeTime
                                            minutes={minutesSince(
                                                incident.openedAt,
                                            )}
                                        />
                                    )}
                                </Link>
                            </li>
                        ))}
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
                <CardTitle className="sam-h3 m-0">
                    Historial de ubicaciones
                </CardTitle>
                <span className="sam-meta">
                    últimas {history.length}{' '}
                    {history.length === 1 ? 'posición' : 'posiciones'}
                </span>
            </CardHeader>
            <CardContent className="p-0">
                {history.length === 0 ? (
                    <p className="px-4 py-6 text-sm text-fg-3">
                        El historial de posiciones se llenará en cuanto el
                        activo empiece a reportar ubicación.
                    </p>
                ) : (
                    <div className="max-h-96 overflow-auto">
                        <table className="w-full border-collapse">
                            <thead>
                                <tr className="sticky top-0 z-10 border-b border-border bg-surface-3 text-[10px] font-semibold tracking-[0.08em] text-fg-3 uppercase">
                                    <th className="w-32 px-4 py-2 text-left">
                                        Cuándo
                                    </th>
                                    <th className="px-2.5 py-2 text-left">
                                        Ubicación
                                    </th>
                                    <th className="w-28 px-2.5 py-2 text-left">
                                        Velocidad
                                    </th>
                                    <th className="w-24 px-2.5 py-2 text-left">
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
                                        <td className="px-4 py-2">
                                            <RelativeTime
                                                minutes={minutesSince(
                                                    entry.recordedAt,
                                                )}
                                            />
                                        </td>
                                        <td className="px-2.5 py-2">
                                            {entry.formattedLocation ? (
                                                <span className="text-[12px] text-fg-2">
                                                    {entry.formattedLocation}
                                                </span>
                                            ) : (
                                                <span className="font-mono text-[11px] text-fg-2 tabular-nums">
                                                    {entry.latitude.toFixed(5)},{' '}
                                                    {entry.longitude.toFixed(5)}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-2.5 py-2 font-mono text-[11px] text-fg-2 tabular-nums">
                                            {entry.speed !== null
                                                ? `${entry.speed.toLocaleString('es')} km/h`
                                                : '—'}
                                        </td>
                                        <td className="px-2.5 py-2 font-mono text-[11px] text-fg-2 tabular-nums">
                                            {entry.heading !== null
                                                ? `${entry.heading}°`
                                                : '—'}
                                        </td>
                                        <td className="px-2.5 py-2 font-mono text-[10px] text-fg-3">
                                            {entry.source}
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
    const { asset, telemetry, locationHistory, incidents } =
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
                {/* Header */}
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button variant="ghost" size="sm" asChild>
                            <Link
                                href={teamSlug ? `/${teamSlug}/assets` : '#'}
                                aria-label="Volver a la flota"
                            >
                                <ChevronLeft size={15} />
                            </Link>
                        </Button>
                        <div className="grid size-10 shrink-0 place-items-center rounded-md border border-border bg-surface-2 text-fg-3">
                            <Truck size={18} strokeWidth={1.5} />
                        </div>
                        <div>
                            <div className="flex items-center gap-2.5">
                                <h1 className="sam-h1">{asset.name}</h1>
                                <AssetStatusBadge status={asset.status} />
                            </div>
                            <p className="sam-meta mt-0.5">
                                {asset.code && (
                                    <span className="font-mono">
                                        {asset.code}
                                    </span>
                                )}
                                {asset.code && asset.type && ' · '}
                                {asset.type?.name}
                                {asset.provider && ` · ${asset.provider}`}
                                {asset.externalPrimaryId && (
                                    <span className="font-mono">
                                        {' '}
                                        · {asset.externalPrimaryId}
                                    </span>
                                )}
                            </p>
                        </div>
                    </div>
                    {asset.lastSeenAt && (
                        <span className="sam-meta">
                            Visto{' '}
                            <RelativeTime
                                minutes={minutesSince(asset.lastSeenAt)}
                            />
                        </span>
                    )}
                </header>

                {/* Devices */}
                {asset.devices.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="sam-caps">Dispositivos</span>
                        {asset.devices.map((device) => (
                            <span
                                key={device.id}
                                className="rounded-sm border border-border bg-surface-2 px-2 py-1 font-mono text-[11px] text-fg-2"
                            >
                                {device.deviceType}
                                {device.externalDeviceId && (
                                    <span className="text-fg-3">
                                        {' '}
                                        · {device.externalDeviceId}
                                    </span>
                                )}
                            </span>
                        ))}
                    </div>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    <LastLocationCard location={asset.lastLocation} />
                    <TelemetryCard telemetry={telemetry} />
                </div>

                <IncidentsCard incidents={incidents} teamSlug={teamSlug} />
                <LocationHistoryCard history={locationHistory} />
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
