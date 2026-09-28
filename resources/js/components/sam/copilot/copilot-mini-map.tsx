import maplibregl from 'maplibre-gl';
import { useEffect, useRef } from 'react';
import {
    resolveCssColor,
    SAM_LAYER_PREFIX,
} from '@/components/sam/map/basemap';
import { MapLoading } from '@/components/sam/map/map-controls';
import { useSamMap } from '@/components/sam/map/use-sam-map';
import { useAppearance } from '@/hooks/use-appearance';

const TRAIL_SOURCE = `${SAM_LAYER_PREFIX}copilot-trail`;

export interface MiniMapPoint {
    latitude: number;
    longitude: number;
    /** Any CSS color, tokens included (`var(--severity-critical)`). */
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

function buildPoint(point: MiniMapPoint): HTMLElement {
    const el = document.createElement(point.href ? 'a' : 'div');
    el.className = 'sam-map-unit';
    el.style.setProperty('--unit', point.color);
    el.style.cursor = point.href ? 'pointer' : 'default';
    el.innerHTML =
        '<span class="sam-map-unit__body"><span class="sam-map-unit__dot"></span></span>';

    if (point.href && el instanceof HTMLAnchorElement) {
        el.href = point.href;
    }

    if (point.emphasis) {
        el.setAttribute('data-emphasis', '');
    }

    if (point.label) {
        el.title = point.label;
        el.setAttribute('aria-label', point.label);
    }

    return el;
}

/**
 * Compact map for chat cards: markers (plus an optional trail) fitted to the
 * data. Only zoom is interactive, so the chat keeps scrolling under it.
 */
export function CopilotMiniMap({
    points,
    trail,
    height = 200,
    zoom = 13,
}: Props) {
    const containerRef = useRef<HTMLDivElement | null>(null);
    const { resolvedAppearance } = useAppearance();
    const first = points[0];
    const { map, status } = useSamMap(containerRef, {
        center: first ? [first.longitude, first.latitude] : [-102, 23.8],
        zoom,
        attributionControl: false,
        dragRotate: false,
        scrollZoom: false,
        touchPitch: false,
    });

    // Markers, and a one-off fit to everything the card shows.
    useEffect(() => {
        if (map === null) {
            return;
        }

        const markers = points.map((point) =>
            new maplibregl.Marker({ element: buildPoint(point) })
                .setLngLat([point.longitude, point.latitude])
                .addTo(map),
        );

        const coordinates: [number, number][] = [
            ...points.map((p): [number, number] => [p.longitude, p.latitude]),
            ...(trail ?? []).map(([lat, lng]): [number, number] => [lng, lat]),
        ];

        if (coordinates.length > 1) {
            const bounds = coordinates.reduce(
                (b, c) => b.extend(c),
                new maplibregl.LngLatBounds(coordinates[0], coordinates[0]),
            );
            map.fitBounds(bounds, { padding: 36, maxZoom: 14, duration: 0 });
        }

        return () => markers.forEach((marker) => marker.remove());
    }, [map, points, trail]);

    // Trail, recolored with the theme.
    useEffect(() => {
        if (map === null || !trail || trail.length < 2) {
            return;
        }

        const data: GeoJSON.Feature<GeoJSON.LineString> = {
            type: 'Feature',
            properties: {},
            geometry: {
                type: 'LineString',
                coordinates: trail.map(([lat, lng]) => [lng, lat]),
            },
        };
        const source = map.getSource(TRAIL_SOURCE) as
            | maplibregl.GeoJSONSource
            | undefined;

        if (source) {
            source.setData(data);
        } else {
            map.addSource(TRAIL_SOURCE, { type: 'geojson', data });
            map.addLayer({
                id: `${TRAIL_SOURCE}-casing`,
                type: 'line',
                source: TRAIL_SOURCE,
                layout: { 'line-cap': 'round', 'line-join': 'round' },
                paint: { 'line-width': 5 },
            });
            map.addLayer({
                id: TRAIL_SOURCE,
                type: 'line',
                source: TRAIL_SOURCE,
                layout: { 'line-cap': 'round', 'line-join': 'round' },
                paint: { 'line-width': 2.5, 'line-opacity': 0.9 },
            });
        }

        map.setPaintProperty(
            `${TRAIL_SOURCE}-casing`,
            'line-color',
            resolveCssColor('var(--map-marker-ring)'),
        );
        map.setPaintProperty(
            TRAIL_SOURCE,
            'line-color',
            resolveCssColor('var(--map-trail)'),
        );
    }, [map, trail, resolvedAppearance]);

    return (
        <div className="relative w-full overflow-hidden" style={{ height }}>
            <div ref={containerRef} className="sam-map h-full w-full" />
            {status === 'loading' && <MapLoading />}
            {status === 'unavailable' && (
                <OfflinePlot points={points} trail={trail} />
            )}
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
                        stroke="var(--map-trail)"
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
                        className="absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-(--map-marker-ring)"
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
