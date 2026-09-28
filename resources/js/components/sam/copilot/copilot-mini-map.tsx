import maplibregl from 'maplibre-gl';
import { useEffect, useRef, useState } from 'react';
import { useAppearance } from '@/hooks/use-appearance';
import 'maplibre-gl/dist/maplibre-gl.css';

// Same free tiles and dark-mode treatment as the live fleet map.
const MAP_STYLE_URL = 'https://tiles.openfreemap.org/styles/liberty';
const DARK_CANVAS_FILTER =
    'invert(1) hue-rotate(180deg) brightness(0.92) contrast(0.95)';

const TRAIL_COLOR = '#3b82f6';

export interface MiniMapPoint {
    latitude: number;
    longitude: number;
    color: string;
    label?: string;
    emphasis?: boolean;
    href?: string;
}

interface Props {
    points: MiniMapPoint[];
    trail?: [number, number][];
    height?: number;
    zoom?: number;
}

/**
 * Compact map for chat cards: markers (plus an optional trail) fitted to the
 * data. Interaction is limited to zoom so the chat keeps scrolling.
 */
export function CopilotMiniMap({
    points,
    trail,
    height = 200,
    zoom = 13,
}: Props) {
    const containerRef = useRef<HTMLDivElement | null>(null);
    const mapRef = useRef<maplibregl.Map | null>(null);
    const [loaded, setLoaded] = useState(false);
    const [failed, setFailed] = useState(false);
    const { resolvedAppearance } = useAppearance();

    useEffect(() => {
        if (!containerRef.current || points.length === 0) {
            return;
        }

        const first = points[0];
        const map = new maplibregl.Map({
            container: containerRef.current,
            style: MAP_STYLE_URL,
            center: [first.longitude, first.latitude],
            zoom,
            attributionControl: false,
            dragRotate: false,
            scrollZoom: false,
            cooperativeGestures: false,
        });
        map.addControl(
            new maplibregl.NavigationControl({ showCompass: false }),
            'top-right',
        );
        mapRef.current = map;

        const markers = points.map((point) => {
            const element = document.createElement(point.href ? 'a' : 'div');

            if (point.href && element instanceof HTMLAnchorElement) {
                element.href = point.href;
            }

            const size = point.emphasis ? 16 : 11;
            element.style.cssText = `width:${size}px;height:${size}px;border-radius:999px;background:${point.color};border:2px solid white;box-shadow:0 0 0 4px color-mix(in oklch, ${point.color} 30%, transparent);cursor:${point.href ? 'pointer' : 'default'}`;

            if (point.label) {
                element.title = point.label;
            }

            return new maplibregl.Marker({ element })
                .setLngLat([point.longitude, point.latitude])
                .addTo(map);
        });

        // Tiles unreachable (offline, blocked network): show the coordinates
        // instead of an eternal spinner.
        map.on('error', () => {
            if (!map.loaded()) {
                setFailed(true);
            }
        });

        map.on('load', () => {
            setLoaded(true);

            if (trail && trail.length > 1) {
                map.addSource('copilot-trail', {
                    type: 'geojson',
                    data: {
                        type: 'Feature',
                        properties: {},
                        geometry: {
                            type: 'LineString',
                            coordinates: trail.map(([lat, lng]) => [lng, lat]),
                        },
                    },
                });
                map.addLayer({
                    id: 'copilot-trail',
                    type: 'line',
                    source: 'copilot-trail',
                    paint: {
                        // MapLibre paints on canvas and can't resolve CSS
                        // variables or oklch(); this is the sRGB twin of
                        // --chart-1 used by the rest of the fleet charts.
                        'line-color': TRAIL_COLOR,
                        'line-width': 3,
                        'line-opacity': 0.75,
                    },
                });
            }

            const coordinates: [number, number][] = [
                ...points.map(
                    (p) => [p.longitude, p.latitude] as [number, number],
                ),
                ...(trail ?? []).map(
                    ([lat, lng]) => [lng, lat] as [number, number],
                ),
            ];

            if (coordinates.length > 1) {
                const bounds = coordinates.reduce(
                    (b, c) => b.extend(c),
                    new maplibregl.LngLatBounds(coordinates[0], coordinates[0]),
                );
                map.fitBounds(bounds, {
                    padding: 36,
                    maxZoom: 14,
                    duration: 0,
                });
            }
        });

        return () => {
            markers.forEach((marker) => marker.remove());
            map.remove();
            mapRef.current = null;
        };
    }, [points, trail, zoom]);

    useEffect(() => {
        const wrap = mapRef.current?.getCanvasContainer();

        if (wrap) {
            wrap.style.filter =
                resolvedAppearance === 'dark' ? DARK_CANVAS_FILTER : '';
        }
    }, [resolvedAppearance, loaded]);

    return (
        <div className="relative w-full overflow-hidden" style={{ height }}>
            <div ref={containerRef} className="h-full w-full" />
            {!loaded && !failed && (
                <div className="absolute inset-0 grid animate-pulse place-items-center bg-surface-2">
                    <span className="rounded-md border border-border bg-surface-1/90 px-3 py-1.5 text-xs text-fg-3">
                        Cargando mapa…
                    </span>
                </div>
            )}
            {failed && <OfflinePlot points={points} trail={trail} />}
        </div>
    );
}

/**
 * Fallback when the basemap can't load: the same points and trail projected
 * onto a plain grid, so the card still shows where things are relative to
 * each other.
 */
function OfflinePlot({
    points,
    trail,
}: {
    points: MiniMapPoint[];
    trail?: [number, number][];
}) {
    const all: [number, number][] = [
        ...points.map((p) => [p.latitude, p.longitude] as [number, number]),
        ...(trail ?? []),
    ];
    const lats = all.map(([lat]) => lat);
    const lngs = all.map(([, lng]) => lng);
    const [minLat, maxLat] = [Math.min(...lats), Math.max(...lats)];
    const [minLng, maxLng] = [Math.min(...lngs), Math.max(...lngs)];
    const spanLat = maxLat - minLat;
    const spanLng = maxLng - minLng;
    // A single position (or all at the same spot) sits in the middle.
    const project = ([lat, lng]: [number, number]) => [
        spanLng > 1e-6 ? 8 + ((lng - minLng) / spanLng) * 84 : 50,
        spanLat > 1e-6 ? 8 + (1 - (lat - minLat) / spanLat) * 84 : 50,
    ];

    return (
        <div className="absolute inset-0 bg-surface-2">
            <svg
                viewBox="0 0 100 100"
                preserveAspectRatio="none"
                className="h-full w-full text-border"
                aria-hidden="true"
            >
                <defs>
                    <pattern
                        id="copilot-grid"
                        width="10"
                        height="10"
                        patternUnits="userSpaceOnUse"
                    >
                        <path
                            d="M 10 0 L 0 0 0 10"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="0.3"
                        />
                    </pattern>
                </defs>
                <rect width="100" height="100" fill="url(#copilot-grid)" />
                {trail && trail.length > 1 && (
                    <polyline
                        points={trail
                            .map((c) => project(c).join(','))
                            .join(' ')}
                        fill="none"
                        stroke="var(--chart-1)"
                        strokeWidth="0.8"
                        vectorEffect="non-scaling-stroke"
                    />
                )}
            </svg>
            {points.map((p, i) => {
                const [x, y] = project([p.latitude, p.longitude]);

                return (
                    <span
                        key={i}
                        title={p.label}
                        className="absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-white"
                        style={{
                            left: `${x}%`,
                            top: `${y}%`,
                            background: p.color,
                        }}
                    />
                );
            })}
            <span className="absolute bottom-1.5 left-2 rounded-sm bg-surface-1/90 px-1.5 py-0.5 text-3xs text-fg-3">
                Mapa base no disponible · vista esquemática
            </span>
        </div>
    );
}
