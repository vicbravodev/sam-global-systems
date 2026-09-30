import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpRight,
    Camera,
    Container,
    ChevronRight,
    Droplet,
    Film,
    Fuel,
    Gauge,
    History,
    Image as ImageIcon,
    Info,
    ListOrdered,
    MapPin,
    Navigation,
    Timer,
    Truck,
    User,
} from 'lucide-react';
import { lazy, memo, Suspense, useMemo, useState } from 'react';
import { SparkArea } from '@/components/sam/charts';
import { SeverityBadge } from '@/components/sam/severity-badge';
import { cn } from '@/lib/utils';
import type {
    AssetBlock,
    AssetPickerBlock,
    AssetsBlock,
    BarsBlock,
    CopilotAssetOption,
    CopilotBlock,
    CopilotIntent,
    DriversBlock,
    EventsBlock,
    FleetMapBlock,
    FuelBlock,
    IncidentsBlock,
    KpisBlock,
    LocationBlock,
    MediaBlock,
    MediaItem,
    MotionState,
    NoticeBlock,
    RankingBlock,
    TelemetryBlock,
    TimelineBlock,
    TimelineItem,
    Tone,
} from '@/types/copilot';
import { dayAndTime, timeAgo, timeOfDay } from './copilot-format';

// maplibre-gl (~1 MB) only loads when an answer actually shows a map: this
// module ships with the Copilot launcher on every Ops page.
const LazyMiniMap = lazy(() =>
    import('./copilot-mini-map').then((module) => ({
        default: module.CopilotMiniMap,
    })),
);

type MiniMapProps = Parameters<typeof LazyMiniMap>[0];

function CopilotMiniMap(props: MiniMapProps) {
    return (
        <Suspense
            fallback={
                <div
                    className="w-full bg-surface-2"
                    style={{ height: props.height ?? 200 }}
                />
            }
        >
            <LazyMiniMap {...props} />
        </Suspense>
    );
}

export interface BlockActions {
    /** Re-ask with an explicit unit (asset picker, "ver más" links). */
    onPickAsset?: (asset: CopilotAssetOption, intent: CopilotIntent) => void;
    compact?: boolean;
}

const MOTION_STYLES: Record<
    MotionState,
    { dot: string; text: string; color: string }
> = {
    moving: {
        dot: 'bg-health-ok',
        text: 'text-health-ok',
        color: 'var(--health-ok)',
    },
    stopped: {
        dot: 'bg-severity-medium',
        text: 'text-severity-medium',
        color: 'var(--severity-medium)',
    },
    no_signal: {
        dot: 'bg-fg-3',
        text: 'text-fg-3',
        color: 'var(--fg-3)',
    },
};

const TONE_CLASSES: Record<string, string> = {
    critical:
        'border-severity-critical/40 bg-severity-critical/8 [&_[data-value]]:text-severity-critical',
    high: 'border-severity-high/40 [&_[data-value]]:text-severity-high',
    ok: 'border-health-ok/40 [&_[data-value]]:text-health-ok',
};

function toneClass(tone: Tone): string {
    return tone ? (TONE_CLASSES[tone] ?? '') : '';
}

const NO_PENDING: { toolCallId: string; tool: string }[] = [];

/**
 * The cards of one answer, append-only in arrival order. Keys are the index
 * (the stored answer keeps the streamed order), so a card mounts once: on a
 * live answer it rises in individually, and a tool still running holds a
 * placeholder of roughly the card's height where its card will land.
 */
export const CopilotBlocks = memo(function CopilotBlocks({
    blocks,
    actions,
    live = false,
    pending = NO_PENDING,
}: {
    blocks: CopilotBlock[];
    actions: BlockActions;
    live?: boolean;
    pending?: { toolCallId: string; tool: string }[];
}) {
    if (blocks.length === 0 && pending.length === 0) {
        return null;
    }

    return (
        <div className="mt-3 flex flex-col gap-2.5">
            {blocks.map((block, index) => (
                <div
                    key={`${block.type}-${index}`}
                    className={live ? 'sam-copilot-rise' : undefined}
                >
                    <BlockSwitch block={block} actions={actions} />
                </div>
            ))}
            {pending.map((placeholder) => (
                <CardPlaceholder
                    key={placeholder.toolCallId}
                    tool={placeholder.tool}
                    compact={actions.compact}
                />
            ))}
        </div>
    );
});

/** Skeleton sized like the card the running tool usually returns. */
function CardPlaceholder({
    tool,
    compact,
}: {
    tool: string;
    compact?: boolean;
}) {
    const body =
        tool === 'asset_location' ? (
            <div
                className="w-full bg-surface-2"
                style={{ height: (compact ? 160 : 220) + 80 }}
            />
        ) : tool === 'asset_media' ? (
            <div className="aspect-video w-full bg-surface-2" />
        ) : tool === 'fleet_overview' ? (
            <div
                className="w-full bg-surface-2"
                style={{ height: compact ? 170 : 240 }}
            />
        ) : (
            <div className="h-16 w-full bg-surface-2" />
        );

    return (
        <div
            aria-hidden
            className="sam-copilot-fade overflow-hidden rounded-lg border border-border bg-surface-1"
        >
            <div className="flex h-8 items-center gap-2 border-b border-border bg-surface-2 px-3">
                <span className="h-2 w-24 rounded-full bg-surface-3 motion-safe:animate-pulse" />
            </div>
            <div className="motion-safe:animate-pulse">{body}</div>
        </div>
    );
}

const BlockSwitch = memo(function BlockSwitch({
    block,
    actions,
}: {
    block: CopilotBlock;
    actions: BlockActions;
}) {
    switch (block.type) {
        case 'asset':
            return <AssetCard block={block} />;
        case 'location':
            return <LocationCard block={block} compact={actions.compact} />;
        case 'telemetry':
            return <TelemetryCard block={block} />;
        case 'fuel':
            return <FuelCard block={block} />;
        case 'media':
            return <MediaCard block={block} />;
        case 'kpis':
            return <KpiGrid block={block} />;
        case 'bars':
            return <BarsCard block={block} />;
        case 'incidents':
            return <IncidentList block={block} />;
        case 'events':
            return <EventList block={block} />;
        case 'drivers':
            return <DriverTable block={block} />;
        case 'assets':
            return <FleetList block={block} />;
        case 'fleet_map':
            return <FleetMap block={block} compact={actions.compact} />;
        case 'asset_picker':
            return <AssetPicker block={block} actions={actions} />;
        case 'ranking':
            return <RankingTable block={block} />;
        case 'timeline':
            return <Timeline block={block} />;
        case 'notice':
            return <Notice block={block} />;
        default:
            return null;
    }
});

// ---------- shared pieces ----------

function Card({
    children,
    className,
}: {
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'overflow-hidden rounded-lg border border-border bg-surface-1',
                className,
            )}
        >
            {children}
        </div>
    );
}

function CardHead({
    icon: Icon,
    title,
    meta,
    href,
}: {
    icon: React.ElementType;
    title: string;
    meta?: React.ReactNode;
    href?: string;
}) {
    return (
        <div className="flex items-center gap-2 border-b border-border bg-surface-2 px-3 py-2">
            <Icon className="size-3.5 shrink-0 text-fg-3" />
            <span className="min-w-0 flex-1 truncate text-xs font-semibold text-fg-1">
                {title}
            </span>
            {meta}
            {href && (
                <Link
                    href={href}
                    className="inline-flex items-center gap-1 text-2xs font-medium text-fg-3 hover:text-fg-1"
                >
                    Abrir <ArrowUpRight className="size-3" />
                </Link>
            )}
        </div>
    );
}

function Stat({
    label,
    value,
    unit,
    hint,
    tone,
}: {
    label: string;
    value: React.ReactNode;
    unit?: string | null;
    hint?: string | null;
    tone?: Tone;
}) {
    return (
        <div className={cn('bg-surface-1 px-3 py-2.5', toneClass(tone))}>
            <div className="text-3xs font-medium tracking-caps text-fg-3 uppercase">
                {label}
            </div>
            <div
                data-value
                className="mt-1 font-mono text-base font-semibold text-fg-1 tabular-nums"
            >
                {value}
                {unit && (
                    <span className="ml-1 text-2xs font-normal text-fg-3">
                        {unit}
                    </span>
                )}
            </div>
            {hint && <div className="mt-0.5 text-3xs text-fg-3">{hint}</div>}
        </div>
    );
}

function MotionPill({ motion, label }: { motion: MotionState; label: string }) {
    const style = MOTION_STYLES[motion];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border border-border bg-surface-2 px-2 py-0.5 text-2xs font-medium',
                style.text,
            )}
        >
            <span className="relative flex size-1.5">
                {motion === 'moving' && (
                    <span
                        className={cn(
                            'absolute inset-0 rounded-full motion-safe:animate-[sam-pulse_1.8s_ease-out_infinite]',
                            style.dot,
                        )}
                    />
                )}
                <span
                    className={cn('relative size-1.5 rounded-full', style.dot)}
                />
            </span>
            {label}
        </span>
    );
}

// ---------- unit ----------

function AssetCard({ block }: { block: AssetBlock }) {
    const a = block.asset;
    const Icon = a.category === 'trailer' ? Container : Truck;

    return (
        <Card>
            <div className="flex items-center gap-3 px-3.5 py-3">
                <div className="grid size-11 shrink-0 place-items-center rounded-md border border-border bg-surface-2 text-fg-2">
                    <Icon className="size-5" strokeWidth={1.5} />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href={a.href}
                            className="truncate text-sm font-semibold text-fg-1 hover:underline"
                        >
                            {a.code ?? a.name}
                        </Link>
                        {a.code && (
                            <span className="truncate text-xs text-fg-3">
                                {a.name}
                            </span>
                        )}
                    </div>
                    <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-2xs text-fg-3">
                        {a.categoryLabel && <span>{a.categoryLabel}</span>}
                        {a.driver ? (
                            <Link
                                href={a.driver.href}
                                className="inline-flex items-center gap-1 hover:text-fg-1"
                            >
                                <User className="size-3" /> {a.driver.name}
                            </Link>
                        ) : (
                            <span className="inline-flex items-center gap-1">
                                <User className="size-3" /> Sin conductor
                            </span>
                        )}
                        {a.provider && <span>{a.provider}</span>}
                    </div>
                </div>
                <div className="flex flex-col items-end gap-1">
                    <MotionPill motion={a.motion} label={a.motionLabel} />
                    <span className="text-3xs text-fg-3">
                        Señal {timeAgo(a.lastSignalAt)}
                    </span>
                </div>
            </div>
            <div className="grid grid-cols-2 gap-px border-t border-border bg-border sm:grid-cols-4">
                <Stat label="Estado" value={a.statusLabel} />
                <Stat
                    label="Velocidad"
                    value={a.location?.speed ?? '—'}
                    unit={a.location?.speed != null ? 'km/h' : null}
                />
                <Stat
                    label="Rumbo"
                    value={
                        a.location?.heading != null
                            ? `${a.location.heading}°`
                            : '—'
                    }
                />
                <Stat
                    label="Posición"
                    value={timeOfDay(a.location?.recordedAt ?? null)}
                    hint={timeAgo(a.location?.recordedAt ?? null)}
                />
            </div>
        </Card>
    );
}

function LocationCard({
    block,
    compact,
}: {
    block: LocationBlock;
    compact?: boolean;
}) {
    const color = MOTION_STYLES[block.motion].color;
    const points = useMemo(
        () => [
            {
                latitude: block.latitude,
                longitude: block.longitude,
                color,
                label: block.assetLabel,
                emphasis: true,
            },
        ],
        [block.latitude, block.longitude, block.assetLabel, color],
    );
    const trail = useMemo(
        () =>
            block.trail.map(
                (p) => [p.latitude, p.longitude] as [number, number],
            ),
        [block.trail],
    );

    return (
        <Card>
            <CardHead
                icon={MapPin}
                title={`Ubicación · ${block.assetLabel}`}
                meta={
                    <MotionPill
                        motion={block.motion}
                        label={block.motionLabel}
                    />
                }
            />
            <CopilotMiniMap
                points={points}
                trail={trail}
                height={compact ? 160 : 220}
            />
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-border px-3 py-2.5 text-xs">
                <span className="min-w-0 flex-1 text-fg-1">
                    {block.formattedLocation ??
                        `${block.latitude.toFixed(5)}, ${block.longitude.toFixed(5)}`}
                </span>
                <span className="font-mono text-fg-2 tabular-nums">
                    {block.speed != null ? `${block.speed} km/h` : '—'}
                </span>
                <span className="text-fg-3">{timeAgo(block.recordedAt)}</span>
            </div>
            <div className="flex flex-wrap gap-1.5 border-t border-border bg-surface-2 px-3 py-2">
                <a
                    href={block.mapsUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1 rounded-md border border-border bg-surface-1 px-2 py-1 text-2xs font-medium text-fg-2 hover:text-fg-1"
                >
                    <Navigation className="size-3" /> Abrir en Google Maps
                </a>
                <Link
                    href={block.href}
                    className="inline-flex items-center gap-1 rounded-md border border-border bg-surface-1 px-2 py-1 text-2xs font-medium text-fg-2 hover:text-fg-1"
                >
                    <Truck className="size-3" /> Ficha de la unidad
                </Link>
                {block.trail.length > 1 && (
                    <span className="ml-auto self-center text-3xs text-fg-3">
                        Recorrido: {block.trail.length} puntos
                        {block.maxSpeed != null &&
                            ` · máx. ${Math.round(block.maxSpeed)} km/h`}
                    </span>
                )}
            </div>
        </Card>
    );
}

// ---------- engine / fuel ----------

function TelemetryCard({ block }: { block: TelemetryBlock }) {
    const series = block.series.points.map((p) => p.v);

    return (
        <Card>
            <CardHead
                icon={Gauge}
                title={block.title}
                meta={
                    <span className="text-3xs text-fg-3">{block.period}</span>
                }
            />
            {block.readings.length > 0 && (
                <div className="grid grid-cols-2 gap-px bg-border sm:grid-cols-3">
                    {block.readings.map((reading) => (
                        <Stat
                            key={reading.key}
                            label={reading.label}
                            value={reading.value ?? '—'}
                            unit={reading.unit}
                            hint={timeAgo(reading.recordedAt)}
                        />
                    ))}
                </div>
            )}
            <div className="grid grid-cols-2 gap-px border-t border-border bg-border sm:grid-cols-4">
                {block.stats.map((stat) => (
                    <Stat key={stat.label} {...stat} />
                ))}
            </div>
            {series.length > 1 && (
                <div className="border-t border-border px-3 pt-2 pb-1">
                    <div className="text-3xs font-medium tracking-caps text-fg-3 uppercase">
                        {block.series.label}
                    </div>
                    <SparkArea
                        data={series}
                        height={56}
                        color="var(--chart-1)"
                        aria-label={block.series.label}
                    />
                </div>
            )}
        </Card>
    );
}

function FuelCard({ block }: { block: FuelBlock }) {
    const level = Math.max(0, Math.min(100, block.current));
    const series = block.series.map((p) => p.v);
    const barColor = block.low
        ? 'bg-severity-critical'
        : level < 40
          ? 'bg-severity-high'
          : 'bg-health-ok';

    return (
        <Card>
            <CardHead
                icon={Fuel}
                title={`Combustible · ${block.assetLabel}`}
                meta={
                    <span className="text-3xs text-fg-3">{block.period}</span>
                }
            />
            <div className="flex items-center gap-4 px-3.5 py-3">
                <div className="flex w-16 shrink-0 flex-col items-center">
                    <div className="relative h-20 w-9 overflow-hidden rounded-md border border-border-strong bg-surface-3">
                        <div
                            className={cn(
                                'absolute inset-x-0 bottom-0 transition-[height] duration-(--motion-slow)',
                                barColor,
                            )}
                            style={{ height: `${level}%` }}
                        />
                    </div>
                    <div className="mt-1 font-mono text-base font-semibold text-fg-1 tabular-nums">
                        {block.current}
                        <span className="text-2xs text-fg-3">{block.unit}</span>
                    </div>
                </div>
                <div className="min-w-0 flex-1">
                    {series.length > 1 ? (
                        <SparkArea
                            data={series}
                            height={70}
                            color={
                                block.low
                                    ? 'var(--severity-critical)'
                                    : 'var(--chart-1)'
                            }
                            aria-label="Nivel de combustible"
                        />
                    ) : (
                        <div className="text-xs text-fg-3">
                            Sin lecturas suficientes en el periodo para
                            graficar.
                        </div>
                    )}
                    <div className="mt-1 text-3xs text-fg-3">
                        Última lectura {timeAgo(block.recordedAt)}
                    </div>
                </div>
            </div>
            <div className="grid grid-cols-3 gap-px border-t border-border bg-border">
                <Stat
                    label="Consumido"
                    value={block.consumed}
                    unit="%"
                    hint="del tanque en el periodo"
                />
                <Stat
                    label="Recargas"
                    value={block.refuels.length}
                    tone={block.refuels.length > 0 ? 'ok' : null}
                />
                <Stat
                    label="Caídas bruscas"
                    value={block.suddenDrops.length}
                    tone={block.suddenDrops.length > 0 ? 'critical' : null}
                    hint={block.suddenDrops.length > 0 ? 'revisar' : null}
                />
            </div>
            {(block.refuels.length > 0 || block.suddenDrops.length > 0) && (
                <ul className="divide-y divide-border border-t border-border text-xs">
                    {block.refuels.slice(-3).map((r) => (
                        <li
                            key={`r-${r.at}`}
                            className="flex items-center gap-2 px-3 py-1.5"
                        >
                            <Droplet className="size-3 text-health-ok" />
                            <span className="flex-1 text-fg-2">Recarga</span>
                            <span className="font-mono text-fg-1 tabular-nums">
                                {r.from}% → {r.to}%
                            </span>
                            <span className="text-3xs text-fg-3">
                                {timeAgo(r.at)}
                            </span>
                        </li>
                    ))}
                    {block.suddenDrops.slice(-3).map((d) => (
                        <li
                            key={`d-${d.at}`}
                            className="flex items-center gap-2 px-3 py-1.5"
                        >
                            <AlertTriangle className="size-3 text-severity-critical" />
                            <span className="flex-1 text-fg-2">
                                Caída brusca
                            </span>
                            <span className="font-mono text-severity-critical tabular-nums">
                                {d.from}% → {d.to}%
                            </span>
                            <span className="text-3xs text-fg-3">
                                {timeAgo(d.at)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

// ---------- media ----------

function isVideo(item: MediaItem): boolean {
    return (
        item.mediaType === 'video' ||
        item.mediaType === 'clip' ||
        (item.mimeType?.startsWith('video/') ?? false)
    );
}

function MediaCard({ block }: { block: MediaBlock }) {
    // By id, not by object: the stored answer may bring fresh item objects
    // (same card, same key) and the selection must survive that.
    const [activeId, setActiveId] = useState<number | null>(
        block.items[0]?.id ?? null,
    );
    const active =
        block.items.find((item) => item.id === activeId) ?? block.items[0];

    if (!active) {
        return null;
    }

    return (
        <Card>
            <CardHead
                icon={Camera}
                title={`Media · ${block.assetLabel}`}
                meta={
                    <span className="text-3xs text-fg-3">
                        {block.items.length} archivo(s)
                    </span>
                }
                href={block.href}
            />
            <div className="bg-black/90">
                {active.url ? (
                    isVideo(active) ? (
                        <video
                            key={active.id}
                            src={active.url}
                            poster={active.thumbnailUrl ?? undefined}
                            controls
                            playsInline
                            preload="metadata"
                            className="aspect-video w-full"
                        />
                    ) : (
                        <img
                            key={active.id}
                            src={active.url}
                            alt={active.eventType ?? 'Snapshot de cámara'}
                            decoding="async"
                            className="aspect-video w-full object-contain"
                        />
                    )
                ) : (
                    <div className="grid aspect-video w-full place-items-center text-xs text-white/70">
                        {active.availability === 'pending'
                            ? 'La media se está descargando del proveedor…'
                            : 'Archivo no disponible'}
                    </div>
                )}
            </div>
            <div className="flex items-center gap-2 border-t border-border px-3 py-2 text-xs">
                {isVideo(active) ? (
                    <Film className="size-3.5 text-fg-3" />
                ) : (
                    <ImageIcon className="size-3.5 text-fg-3" />
                )}
                <span className="min-w-0 flex-1 truncate text-fg-1">
                    {active.eventType ?? 'Evento'}
                    {active.roleLabel && (
                        <span className="text-fg-3"> · {active.roleLabel}</span>
                    )}
                </span>
                <span className="text-3xs text-fg-3">
                    {timeAgo(active.capturedAt)}
                </span>
                {active.eventHref && (
                    <Link
                        href={active.eventHref}
                        className="inline-flex items-center gap-1 text-2xs font-medium text-fg-3 hover:text-fg-1"
                    >
                        Evento <ArrowUpRight className="size-3" />
                    </Link>
                )}
            </div>
            {block.items.length > 1 && (
                <div className="flex gap-1.5 overflow-x-auto border-t border-border bg-surface-2 p-2">
                    {block.items.map((item) => (
                        <button
                            key={item.id}
                            type="button"
                            onClick={() => setActiveId(item.id)}
                            aria-pressed={item.id === active.id}
                            className={cn(
                                'relative grid aspect-video w-20 shrink-0 cursor-pointer place-items-center overflow-hidden rounded-sm border bg-surface-3 text-fg-3 transition-transform duration-(--motion-fast) ease-(--ease-out) active:scale-97',
                                item.id === active.id
                                    ? 'border-primary ring-2 ring-primary/30'
                                    : 'border-border hover:border-border-strong',
                            )}
                            aria-label={`Ver ${item.eventType ?? 'media'}`}
                        >
                            <MediaThumb item={item} />
                            <span className="absolute right-0.5 bottom-0.5 rounded-sm bg-black/70 px-1 font-mono text-3xs text-white">
                                {timeOfDay(item.capturedAt)}
                            </span>
                        </button>
                    ))}
                </div>
            )}
        </Card>
    );
}

/**
 * Real thumbnail of a carousel item: the signed snapshot / clip thumbnail
 * when the server sends one, else the clip's own first frame (metadata
 * only, muted), else the type icon.
 */
function MediaThumb({ item }: { item: MediaItem }) {
    const [failed, setFailed] = useState(false);

    if (item.thumbnailUrl && !failed) {
        return (
            <img
                src={item.thumbnailUrl}
                alt=""
                loading="lazy"
                decoding="async"
                onError={() => setFailed(true)}
                className="absolute inset-0 size-full object-cover"
            />
        );
    }

    if (isVideo(item) && item.url && !failed) {
        return (
            <video
                // `#t` asks for a frame past the (often black) first one.
                src={`${item.url}#t=0.5`}
                preload="metadata"
                muted
                playsInline
                tabIndex={-1}
                aria-hidden
                onError={() => setFailed(true)}
                className="pointer-events-none absolute inset-0 size-full object-cover"
            />
        );
    }

    if (!isVideo(item) && item.url && !failed) {
        return (
            <img
                src={item.url}
                alt=""
                loading="lazy"
                decoding="async"
                onError={() => setFailed(true)}
                className="absolute inset-0 size-full object-cover"
            />
        );
    }

    return isVideo(item) ? (
        <Film className="size-4" />
    ) : (
        <ImageIcon className="size-4" />
    );
}

// ---------- KPIs & charts ----------

function KpiGrid({ block }: { block: KpisBlock }) {
    return (
        <div className="grid grid-cols-2 gap-1.5 sm:grid-cols-3 lg:grid-cols-[repeat(auto-fit,minmax(110px,1fr))]">
            {block.items.map((item) => (
                <div
                    key={item.label}
                    className={cn(
                        'rounded-md border border-border bg-surface-2 px-3 py-2.5',
                        toneClass(item.tone),
                    )}
                >
                    <div
                        data-value
                        className="font-mono text-xl leading-none font-semibold tracking-tight text-fg-1 tabular-nums"
                    >
                        {item.value}
                    </div>
                    <div className="mt-1.5 text-3xs font-medium tracking-caps text-fg-3 uppercase">
                        {item.label}
                    </div>
                    {item.hint && (
                        <div className="mt-0.5 text-3xs text-fg-3">
                            {item.hint}
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}

function BarsCard({ block }: { block: BarsBlock }) {
    const max = Math.max(1, ...block.items.map((i) => i.value));
    const color =
        block.tone === 'critical' ? 'bg-severity-critical' : 'bg-primary';

    return (
        <Card>
            <CardHead
                icon={Timer}
                title={block.title}
                meta={
                    <span className="font-mono text-2xs text-fg-2 tabular-nums">
                        {block.total} total
                    </span>
                }
            />
            <div className="flex h-28 items-end gap-1.5 px-3 pt-3">
                {block.items.map((item, index) => (
                    <div
                        key={`${item.label}-${index}`}
                        className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1"
                        title={`${item.label}: ${item.value}`}
                    >
                        <span className="font-mono text-3xs text-fg-3 tabular-nums">
                            {item.value > 0 ? item.value : ''}
                        </span>
                        <div
                            className={cn(
                                'w-full max-w-7 origin-bottom rounded-t-sm motion-safe:animate-[sam-copilot-bar_var(--motion-slow)_var(--ease-out)_both]',
                                item.value > 0 ? color : 'bg-surface-3',
                            )}
                            style={{
                                height: `${Math.max(4, (item.value / max) * 80)}%`,
                                animationDelay: `${index * 25}ms`,
                            }}
                        />
                    </div>
                ))}
            </div>
            <div className="flex gap-1.5 px-3 pb-2">
                {block.items.map((item, index) => (
                    <div
                        key={`${item.label}-l-${index}`}
                        className="min-w-0 flex-1 truncate text-center font-mono text-3xs text-fg-3"
                    >
                        {item.label}
                    </div>
                ))}
            </div>
            {block.legend.length > 0 && (
                <div className="flex flex-wrap gap-1.5 border-t border-border bg-surface-2 px-3 py-2">
                    {block.legend.map((item) => (
                        <span
                            key={item.label}
                            className="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface-1 px-2 py-0.5 text-2xs text-fg-2"
                        >
                            {item.label}
                            <span className="font-mono font-semibold text-fg-1 tabular-nums">
                                {item.value}
                            </span>
                        </span>
                    ))}
                </div>
            )}
        </Card>
    );
}

// ---------- lists ----------

function IncidentList({ block }: { block: IncidentsBlock }) {
    return (
        <Card>
            <CardHead icon={AlertTriangle} title={block.title} />
            <ul className="divide-y divide-border">
                {block.items.map((item) => (
                    <li key={item.id}>
                        <Link
                            href={item.href}
                            className="grid grid-cols-[auto_minmax(0,1fr)_auto_auto] items-center gap-2.5 px-3 py-2 hover:bg-surface-2"
                        >
                            <SeverityBadge level={item.severity} />
                            <span className="min-w-0">
                                <span className="block truncate text-xs font-medium text-fg-1">
                                    {item.title}
                                </span>
                                <span className="block truncate font-mono text-3xs text-fg-3">
                                    {item.reference ?? `#${item.id}`}
                                    {item.assetCode && ` · ${item.assetCode}`}
                                    {item.driverName && ` · ${item.driverName}`}
                                </span>
                            </span>
                            <span
                                className={cn(
                                    'text-2xs',
                                    item.slaBreached
                                        ? 'font-semibold text-severity-critical'
                                        : 'text-fg-3',
                                )}
                            >
                                {item.slaBreached
                                    ? 'SLA vencido'
                                    : item.statusLabel}
                            </span>
                            <ChevronRight className="size-3.5 text-fg-3" />
                        </Link>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

function EventList({ block }: { block: EventsBlock }) {
    return (
        <Card>
            <CardHead icon={AlertTriangle} title={block.title} />
            <ul className="divide-y divide-border">
                {block.items.map((item) => (
                    <li key={item.id}>
                        <Link
                            href={item.href}
                            className="grid grid-cols-[auto_minmax(0,1fr)_auto_auto] items-center gap-2.5 px-3 py-2 hover:bg-surface-2"
                        >
                            <SeverityBadge level={item.severity} />
                            <span className="min-w-0">
                                <span className="block truncate text-xs font-medium text-fg-1">
                                    {item.assetCode ?? 'Unidad desconocida'}
                                    {item.driverName && (
                                        <span className="font-normal text-fg-3">
                                            {' '}
                                            · {item.driverName}
                                        </span>
                                    )}
                                </span>
                                <span className="block font-mono text-3xs text-fg-3">
                                    {timeOfDay(item.occurredAt)} ·{' '}
                                    {timeAgo(item.occurredAt)}
                                </span>
                            </span>
                            <span className="text-2xs text-fg-2">
                                {item.statusLabel}
                            </span>
                            <ChevronRight className="size-3.5 text-fg-3" />
                        </Link>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

function DriverTable({ block }: { block: DriversBlock }) {
    return (
        <Card>
            <CardHead icon={User} title="Ranking de conductores por riesgo" />
            <div className="overflow-x-auto">
                <table className="w-full text-xs">
                    <thead>
                        <tr className="border-b border-border bg-surface-3 text-left text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                            <th className="px-3 py-1.5">#</th>
                            <th className="px-2 py-1.5">Conductor</th>
                            <th className="px-2 py-1.5">Score</th>
                            <th className="px-2 py-1.5 text-right">Inc.</th>
                            <th className="px-2 py-1.5 text-right">Bruscos</th>
                            <th className="px-3 py-1.5 text-right">Fatiga</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                        {block.items.map((driver, index) => {
                            const color =
                                driver.score >= 75
                                    ? 'bg-severity-critical'
                                    : driver.score >= 50
                                      ? 'bg-severity-high'
                                      : 'bg-severity-low';

                            return (
                                <tr
                                    key={driver.id}
                                    className="hover:bg-surface-2"
                                >
                                    <td className="px-3 py-2 font-mono text-fg-3">
                                        {index + 1}
                                    </td>
                                    <td className="px-2 py-2">
                                        <Link
                                            href={driver.href}
                                            className="font-medium text-fg-1 hover:underline"
                                        >
                                            {driver.name}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2">
                                        <span className="flex items-center gap-2">
                                            <span className="w-7 font-mono font-semibold text-fg-1 tabular-nums">
                                                {driver.score}
                                            </span>
                                            <span className="h-1 w-14 overflow-hidden rounded-full bg-surface-3">
                                                <span
                                                    className={cn(
                                                        'block h-full',
                                                        color,
                                                    )}
                                                    style={{
                                                        width: `${Math.min(100, driver.score)}%`,
                                                    }}
                                                />
                                            </span>
                                        </span>
                                    </td>
                                    <td className="px-2 py-2 text-right font-mono tabular-nums">
                                        {driver.incidents}
                                    </td>
                                    <td className="px-2 py-2 text-right font-mono tabular-nums">
                                        {driver.harsh}
                                    </td>
                                    <td className="px-3 py-2 text-right font-mono tabular-nums">
                                        {driver.fatigue}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </Card>
    );
}

function formatValue(value: number): string {
    return value.toLocaleString(undefined, { maximumFractionDigits: 2 });
}

function RankingTable({ block }: { block: RankingBlock }) {
    const max = Math.max(1, ...block.items.map((i) => i.value));

    return (
        <Card>
            <CardHead
                icon={ListOrdered}
                title={`Ranking de unidades · ${block.label}`}
            />
            <div className="overflow-x-auto">
                <table className="w-full text-xs">
                    <thead>
                        <tr className="border-b border-border bg-surface-3 text-left text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                            <th className="px-3 py-1.5">#</th>
                            <th className="px-2 py-1.5">Unidad</th>
                            <th className="px-3 py-1.5 text-right">
                                {block.unit}
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                        {block.items.map((item, index) => (
                            <tr
                                key={item.assetId}
                                className="hover:bg-surface-2"
                            >
                                <td className="px-3 py-2 font-mono text-fg-3">
                                    {index + 1}
                                </td>
                                <td className="px-2 py-2">
                                    <span className="flex min-w-0 items-center gap-2">
                                        <Link
                                            href={item.href}
                                            className="font-mono font-semibold text-fg-1 hover:underline"
                                        >
                                            {item.code ?? item.name}
                                        </Link>
                                        {item.code && (
                                            <span className="truncate text-fg-3">
                                                {item.name}
                                            </span>
                                        )}
                                        {item.outlier && (
                                            <span className="shrink-0 rounded-full border border-severity-high/40 bg-severity-high/8 px-1.5 py-px text-3xs font-medium text-severity-high">
                                                atípica
                                            </span>
                                        )}
                                    </span>
                                </td>
                                <td className="px-3 py-2">
                                    <span className="flex items-center justify-end gap-2">
                                        <span className="h-1 w-14 overflow-hidden rounded-full bg-surface-3">
                                            <span
                                                className={cn(
                                                    'block h-full',
                                                    item.outlier
                                                        ? 'bg-severity-high'
                                                        : 'bg-primary',
                                                )}
                                                style={{
                                                    width: `${Math.min(100, (item.value / max) * 100)}%`,
                                                }}
                                            />
                                        </span>
                                        <span className="w-14 text-right font-mono font-semibold text-fg-1 tabular-nums">
                                            {formatValue(item.value)}
                                        </span>
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="border-t border-border bg-surface-2 text-fg-2">
                            <td className="px-3 py-1.5" />
                            <td className="px-2 py-1.5 text-2xs">
                                Promedio de flota
                            </td>
                            <td className="px-3 py-1.5 text-right font-mono text-2xs tabular-nums">
                                {formatValue(block.average)} {block.unit}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Card>
    );
}

const TIMELINE_ICONS: Record<TimelineItem['kind'], React.ElementType> = {
    event: Gauge,
    incident: AlertTriangle,
    idle: Timer,
};

function Timeline({ block }: { block: TimelineBlock }) {
    return (
        <Card>
            <CardHead icon={History} title="Línea de tiempo" />
            <ol className="divide-y divide-border">
                {block.items.map((item, index) => {
                    const Icon = TIMELINE_ICONS[item.kind];
                    const body = (
                        <>
                            <Icon
                                className={cn(
                                    'size-3.5 shrink-0',
                                    item.kind === 'incident'
                                        ? 'text-severity-high'
                                        : 'text-fg-3',
                                )}
                            />
                            <span className="min-w-0">
                                <span className="block truncate text-xs font-medium text-fg-1">
                                    {item.label}
                                    {item.kind === 'idle' &&
                                        item.minutes !== undefined && (
                                            <span className="font-normal text-fg-3">
                                                {' '}
                                                · {item.minutes} min
                                            </span>
                                        )}
                                </span>
                                <span className="block font-mono text-3xs text-fg-3">
                                    {dayAndTime(item.at)}
                                </span>
                            </span>
                            {item.severity ? (
                                <SeverityBadge level={item.severity} />
                            ) : (
                                <span />
                            )}
                        </>
                    );
                    const rowClass =
                        'grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2.5 px-3 py-2';

                    return (
                        <li key={`${item.kind}-${item.at}-${index}`}>
                            {item.href ? (
                                <Link
                                    href={item.href}
                                    className={cn(
                                        rowClass,
                                        'hover:bg-surface-2',
                                    )}
                                >
                                    {body}
                                </Link>
                            ) : (
                                <div className={rowClass}>{body}</div>
                            )}
                        </li>
                    );
                })}
            </ol>
            {block.href &&
                block.total !== undefined &&
                block.total > block.items.length && (
                    <Link
                        href={block.href}
                        className="flex items-center justify-center gap-1 border-t border-border bg-surface-2 px-3 py-2 text-2xs font-medium text-fg-2 hover:bg-surface-3 hover:text-fg-1"
                    >
                        Ver los {block.total} eventos
                        <ArrowUpRight className="size-3" />
                    </Link>
                )}
        </Card>
    );
}

function FleetList({ block }: { block: AssetsBlock }) {
    return (
        <Card>
            <CardHead
                icon={Truck}
                title={block.title}
                meta={
                    block.total > block.items.length ? (
                        <span className="text-3xs text-fg-3">
                            {block.items.length} de {block.total}
                        </span>
                    ) : null
                }
            />
            <ul className="divide-y divide-border">
                {block.items.map((row) => (
                    <li key={row.id}>
                        <Link
                            href={row.href}
                            className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 px-3 py-2 hover:bg-surface-2 sm:grid-cols-[7rem_minmax(0,1fr)_auto]"
                        >
                            <span className="truncate font-mono text-xs font-semibold text-fg-1">
                                {row.code ?? row.name}
                            </span>
                            <span className="hidden min-w-0 truncate text-2xs text-fg-3 sm:block">
                                {row.location?.formattedLocation ??
                                    (row.location
                                        ? `${row.location.latitude.toFixed(3)}, ${row.location.longitude.toFixed(3)}`
                                        : 'Sin posición')}
                                {row.driverName && ` · ${row.driverName}`}
                            </span>
                            <MotionPill
                                motion={row.motion}
                                label={
                                    row.motion === 'moving' &&
                                    row.location?.speed != null
                                        ? `${Math.round(row.location.speed)} km/h`
                                        : row.motionLabel
                                }
                            />
                        </Link>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

function FleetMap({
    block,
    compact,
}: {
    block: FleetMapBlock;
    compact?: boolean;
}) {
    const points = useMemo(
        () =>
            block.points.map((p) => ({
                latitude: p.latitude,
                longitude: p.longitude,
                color:
                    p.status === 'critical' || p.status === 'alert'
                        ? 'var(--severity-critical)'
                        : MOTION_STYLES[p.motion].color,
                label: p.code,
            })),
        [block.points],
    );

    return (
        <Card>
            <CardHead icon={MapPin} title="Mapa de la flota" />
            <CopilotMiniMap
                points={points}
                height={compact ? 170 : 240}
                zoom={5}
            />
            <div className="flex flex-wrap gap-3 border-t border-border px-3 py-2 text-2xs text-fg-2">
                {(['moving', 'stopped', 'no_signal'] as MotionState[]).map(
                    (m) => (
                        <span
                            key={m}
                            className="inline-flex items-center gap-1.5"
                        >
                            <span
                                className={cn(
                                    'size-2 rounded-full',
                                    MOTION_STYLES[m].dot,
                                )}
                            />
                            {m === 'moving'
                                ? 'En ruta'
                                : m === 'stopped'
                                  ? 'Detenida'
                                  : 'Sin señal'}
                        </span>
                    ),
                )}
                <span className="inline-flex items-center gap-1.5">
                    <span className="size-2 rounded-full bg-severity-critical" />
                    En alerta
                </span>
            </div>
        </Card>
    );
}

// ---------- interaction ----------

function AssetPicker({
    block,
    actions,
}: {
    block: AssetPickerBlock;
    actions: BlockActions;
}) {
    return (
        <Card className="border-ai-accent/40">
            <div className="px-3 py-2.5 text-xs text-fg-2">{block.text}</div>
            <div className="flex flex-wrap gap-1.5 border-t border-border bg-surface-2 p-2.5">
                {block.options.length === 0 && (
                    <span className="text-2xs text-fg-3">
                        No hay unidades registradas todavía.
                    </span>
                )}
                {block.options.map((option) => (
                    <button
                        key={option.id}
                        type="button"
                        onClick={() =>
                            actions.onPickAsset?.(option, block.intent)
                        }
                        className="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-border bg-surface-1 px-2.5 py-1 text-xs text-fg-1 transition-colors hover:border-ai-accent/60 hover:bg-ai-accent-bg"
                    >
                        <Truck className="size-3 text-fg-3" />
                        <span className="font-mono font-semibold">
                            {option.code ?? option.name}
                        </span>
                        {option.code && (
                            <span className="max-w-32 truncate text-fg-3">
                                {option.name}
                            </span>
                        )}
                    </button>
                ))}
            </div>
        </Card>
    );
}

function Notice({ block }: { block: NoticeBlock }) {
    return (
        <div
            className={cn(
                'flex items-start gap-2 rounded-md border px-3 py-2 text-xs',
                block.tone === 'warn'
                    ? 'border-severity-high/40 bg-severity-high/8 text-fg-1'
                    : 'border-border bg-surface-2 text-fg-2',
            )}
        >
            {block.tone === 'warn' ? (
                <AlertTriangle className="mt-0.5 size-3.5 shrink-0 text-severity-high" />
            ) : (
                <Info className="mt-0.5 size-3.5 shrink-0 text-fg-3" />
            )}
            <span>{block.text}</span>
        </div>
    );
}
