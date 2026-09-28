import maplibregl from 'maplibre-gl';
import { useEffect, useRef, useState } from 'react';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import 'maplibre-gl/dist/maplibre-gl.css';

// Same free vector tiles and dark-mode treatment as the live fleet map so a
// unit looks identical on its detail page and on the fleet map.
const MAP_STYLE_URL = 'https://tiles.openfreemap.org/styles/liberty';
const DARK_CANVAS_FILTER =
    'invert(1) hue-rotate(180deg) brightness(0.92) contrast(0.95)';

const TONE_COLORS = {
    ok: 'var(--severity-low)',
    warn: 'var(--severity-medium)',
    high: 'var(--severity-high)',
    critical: 'var(--severity-critical)',
    neutral: 'var(--fg-3)',
    primary: 'var(--primary)',
} as const;

export type PointTone = keyof typeof TONE_COLORS;

interface Props {
    latitude: number;
    longitude: number;
    /** Degrees, 0 = north. Draws a heading wedge when known. */
    heading?: number | null;
    label?: string;
    tone?: PointTone;
    zoom?: number;
    /** Extra points drawn as a faint trail (oldest first). */
    trail?: { latitude: number; longitude: number }[];
    className?: string;
}

function buildMarker(
    label: string | undefined,
    tone: PointTone,
    heading: number | null | undefined,
): HTMLDivElement {
    const el = document.createElement('div');
    el.className = 'relative grid place-items-center';
    el.style.width = '28px';
    el.style.height = '28px';

    if (heading !== null && heading !== undefined) {
        const wedge = document.createElement('div');
        wedge.style.position = 'absolute';
        wedge.style.width = '0';
        wedge.style.height = '0';
        wedge.style.borderLeft = '7px solid transparent';
        wedge.style.borderRight = '7px solid transparent';
        wedge.style.borderBottom = `14px solid ${TONE_COLORS[tone]}`;
        wedge.style.opacity = '0.55';
        wedge.style.transformOrigin = '50% 100%';
        wedge.style.transform = `translateY(-11px) rotate(${heading}deg)`;
        el.appendChild(wedge);
    }

    const dot = document.createElement('div');
    dot.className = 'rounded-full border-2 border-white shadow-md';
    dot.style.width = '14px';
    dot.style.height = '14px';
    dot.style.backgroundColor = TONE_COLORS[tone];
    dot.style.position = 'relative';
    el.appendChild(dot);

    if (label) {
        el.title = label;
        el.setAttribute('aria-label', label);
    }

    return el;
}

/**
 * Single-point map for detail pages (unit position, event location). Static
 * by intent: no clustering, one marker, optional heading wedge and a faint
 * trail of previous positions. Re-centers when the point changes.
 */
export function PointMap({
    latitude,
    longitude,
    heading = null,
    label,
    tone = 'primary',
    zoom = 13,
    trail = [],
    className,
}: Props) {
    const { resolvedAppearance } = useAppearance();
    const containerRef = useRef<HTMLDivElement | null>(null);
    const mapRef = useRef<maplibregl.Map | null>(null);
    const markerRef = useRef<maplibregl.Marker | null>(null);
    const [loaded, setLoaded] = useState(false);
    const [unavailable, setUnavailable] = useState(false);

    useEffect(() => {
        if (containerRef.current === null) {
            return;
        }

        let map: maplibregl.Map;

        try {
            map = new maplibregl.Map({
                container: containerRef.current,
                style: MAP_STYLE_URL,
                center: [longitude, latitude],
                zoom,
                attributionControl: { compact: true },
            });
        } catch {
            // No WebGL (old GPU, headless, remote desktop): the page must
            // still render; the coordinates and the external link below the
            // map carry the information.
            setUnavailable(true);

            return;
        }

        map.addControl(new maplibregl.NavigationControl(), 'top-right');
        map.on('load', () => setLoaded(true));
        mapRef.current = map;

        return () => {
            markerRef.current?.remove();
            markerRef.current = null;
            map.remove();
            mapRef.current = null;
            setLoaded(false);
        };
        // The map is created once; position/marker updates are handled below.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Marker + trail follow the point.
    useEffect(() => {
        const map = mapRef.current;

        if (map === null || !loaded) {
            return;
        }

        markerRef.current?.remove();
        markerRef.current = new maplibregl.Marker({
            element: buildMarker(label, tone, heading),
        })
            .setLngLat([longitude, latitude])
            .addTo(map);
        map.easeTo({ center: [longitude, latitude], duration: 400 });

        const sourceId = 'point-trail';
        const coordinates = [
            ...trail.map((p) => [p.longitude, p.latitude]),
            [longitude, latitude],
        ];
        const data: GeoJSON.Feature<GeoJSON.LineString> = {
            type: 'Feature',
            properties: {},
            geometry: { type: 'LineString', coordinates },
        };

        const existing = map.getSource(sourceId) as
            | maplibregl.GeoJSONSource
            | undefined;

        if (existing) {
            existing.setData(data);
        } else if (coordinates.length > 1) {
            map.addSource(sourceId, { type: 'geojson', data });
            map.addLayer({
                id: `${sourceId}-line`,
                type: 'line',
                source: sourceId,
                paint: {
                    'line-color': TONE_COLORS.primary,
                    'line-width': 2,
                    'line-opacity': 0.55,
                },
            });
        }
    }, [latitude, longitude, heading, label, tone, trail, loaded]);

    useEffect(() => {
        const map = mapRef.current;

        if (map === null || !loaded) {
            return;
        }

        map.getCanvasContainer().style.filter =
            resolvedAppearance === 'dark' ? DARK_CANVAS_FILTER : '';
    }, [resolvedAppearance, loaded]);

    if (unavailable) {
        return (
            <div
                className={cn(
                    'grid h-full w-full place-items-center bg-surface-2 text-center',
                    className,
                )}
            >
                <span className="rounded-md border border-border bg-surface-1/90 px-3 py-1.5 text-xs text-fg-3">
                    Mapa no disponible en este navegador ·{' '}
                    <span className="font-mono tabular-nums">
                        {latitude.toFixed(5)}, {longitude.toFixed(5)}
                    </span>
                </span>
            </div>
        );
    }

    return (
        <div className={cn('relative h-full w-full', className)}>
            <div ref={containerRef} className="h-full w-full" />
            {!loaded && (
                <div className="absolute inset-0 z-10 animate-pulse bg-surface-2">
                    <div className="grid h-full place-items-center">
                        <span className="rounded-md border border-border bg-surface-1/90 px-3 py-1.5 text-xs text-fg-3">
                            Cargando mapa…
                        </span>
                    </div>
                </div>
            )}
        </div>
    );
}
