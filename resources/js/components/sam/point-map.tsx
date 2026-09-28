import maplibregl from 'maplibre-gl';
import { useCallback, useEffect, useMemo, useRef } from 'react';
import {
    resolveCssColor,
    SAM_LAYER_PREFIX,
} from '@/components/sam/map/basemap';
import {
    MapControls,
    MapLoading,
    MapUnavailable,
} from '@/components/sam/map/map-controls';
import {
    createPinMarker,
    createUnitMarker,
    MOVING_MIN_KPH,
    updateUnitMarker,
} from '@/components/sam/map/markers';
import { useSamMap } from '@/components/sam/map/use-sam-map';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import type { AssetStatusValue } from '@/types/assets';

const TONE_COLORS = {
    ok: 'var(--severity-low)',
    warn: 'var(--severity-medium)',
    high: 'var(--severity-high)',
    critical: 'var(--severity-critical)',
    neutral: 'var(--fg-3)',
    primary: 'var(--primary)',
} as const;

export type PointTone = keyof typeof TONE_COLORS;

// Status a tone stands for when the point is a unit, so the detail map draws
// the same marker as the fleet map.
const TONE_STATUS: Record<PointTone, AssetStatusValue> = {
    ok: 'active',
    warn: 'maintenance',
    high: 'alert',
    critical: 'critical',
    neutral: 'offline',
    primary: 'active',
};

const TRAIL_SOURCE = `${SAM_LAYER_PREFIX}trail`;
const TRAIL_CASING = `${SAM_LAYER_PREFIX}trail-casing`;
const TRAIL_LINE = `${SAM_LAYER_PREFIX}trail-line`;
const TRAIL_START = `${SAM_LAYER_PREFIX}trail-start`;

interface Props {
    latitude: number;
    longitude: number;
    /** Degrees, 0 = north. A unit shows it as an arrow while moving. */
    heading?: number | null;
    /** km/h; with a heading, draws the unit as moving. */
    speed?: number | null;
    label?: string;
    tone?: PointTone;
    /**
     * `unit`: the fleet map's vehicle marker (status + heading).
     * `pin`: a place where something happened (event, incident).
     */
    variant?: 'unit' | 'pin';
    zoom?: number;
    /** Previous positions, oldest first, drawn as a fading trail. */
    trail?: { latitude: number; longitude: number }[];
    className?: string;
}

/**
 * Single-point map for detail pages (unit position, event location). No
 * clustering: one marker, optional heading and a trail that fades from the
 * oldest point to the current one. Follows the point when it changes.
 */
export function PointMap({
    latitude,
    longitude,
    heading = null,
    speed = null,
    label,
    tone = 'primary',
    variant = 'unit',
    zoom = 13,
    trail = [],
    className,
}: Props) {
    const containerRef = useRef<HTMLDivElement | null>(null);
    const { resolvedAppearance } = useAppearance();
    const { map, status } = useSamMap(containerRef, {
        center: [longitude, latitude],
        zoom,
        maxZoom: 18,
        // Detail pages scroll: the wheel scrolls the page, the map zooms with
        // the controls, a pinch or ctrl + wheel.
        cooperativeGestures: false,
        scrollZoom: false,
    });
    const markerRef = useRef<maplibregl.Marker | null>(null);

    const coordinates = useMemo<[number, number][]>(
        () => [
            ...trail.map((p): [number, number] => [p.longitude, p.latitude]),
            [longitude, latitude],
        ],
        [trail, latitude, longitude],
    );

    const unitState = useMemo(
        () => ({
            status: TONE_STATUS[tone],
            heading,
            moving:
                heading !== null && (speed === null || speed >= MOVING_MIN_KPH),
            label: null,
            ariaLabel: label ?? 'Posición',
            emphasis: true,
        }),
        [tone, heading, speed, label],
    );

    // Marker: created once per variant, then patched and moved in place.
    useEffect(() => {
        if (map === null) {
            return;
        }

        const element =
            variant === 'pin'
                ? createPinMarker(TONE_COLORS[tone], label)
                : createUnitMarker(unitState, false);

        if (variant === 'unit') {
            element.style.setProperty('--unit', TONE_COLORS[tone]);
        }

        markerRef.current = new maplibregl.Marker({ element })
            .setLngLat([longitude, latitude])
            .addTo(map);

        return () => {
            markerRef.current?.remove();
            markerRef.current = null;
        };
        // Position and state are applied by the effect below.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [map, variant]);

    useEffect(() => {
        const marker = markerRef.current;

        if (map === null || marker === null) {
            return;
        }

        marker.setLngLat([longitude, latitude]);
        const el = marker.getElement();

        if (variant === 'unit') {
            updateUnitMarker(el, unitState);
            el.style.setProperty('--unit', TONE_COLORS[tone]);
        } else {
            el.style.setProperty('--unit', TONE_COLORS[tone]);
        }

        if (!map.getBounds().contains([longitude, latitude])) {
            map.easeTo({ center: [longitude, latitude], duration: 400 });
        }
    }, [map, latitude, longitude, unitState, tone, variant]);

    // Trail: one GeoJSON line with a gradient from faint (oldest) to solid
    // (now), over a casing in the basemap color so it reads on any road.
    useEffect(() => {
        if (map === null) {
            return;
        }

        const color = resolveCssColor('var(--map-trail)');
        const casing = resolveCssColor('var(--map-marker-ring)');
        const faint = resolveCssColor(
            'color-mix(in oklch, var(--map-trail) 15%, transparent)',
        );
        const data: GeoJSON.Feature<GeoJSON.LineString> = {
            type: 'Feature',
            properties: {},
            geometry: { type: 'LineString', coordinates },
        };
        const startData: GeoJSON.Feature<GeoJSON.Point> = {
            type: 'Feature',
            properties: {},
            geometry: { type: 'Point', coordinates: coordinates[0] },
        };
        const hasTrail = coordinates.length > 1;
        const source = map.getSource(TRAIL_SOURCE) as
            | maplibregl.GeoJSONSource
            | undefined;

        if (!hasTrail) {
            [TRAIL_START, TRAIL_LINE, TRAIL_CASING].forEach((id) => {
                if (map.getLayer(id)) {
                    map.removeLayer(id);
                }
            });

            if (source) {
                map.removeSource(TRAIL_SOURCE);
                map.removeSource(`${TRAIL_SOURCE}-start`);
            }

            return;
        }

        if (source) {
            source.setData(data);
            (
                map.getSource(
                    `${TRAIL_SOURCE}-start`,
                ) as maplibregl.GeoJSONSource
            ).setData(startData);
        } else {
            map.addSource(TRAIL_SOURCE, {
                type: 'geojson',
                data,
                lineMetrics: true,
            });
            map.addSource(`${TRAIL_SOURCE}-start`, {
                type: 'geojson',
                data: startData,
            });
            map.addLayer({
                id: TRAIL_CASING,
                type: 'line',
                source: TRAIL_SOURCE,
                layout: { 'line-cap': 'round', 'line-join': 'round' },
                paint: { 'line-width': 6, 'line-opacity': 0.9 },
            });
            map.addLayer({
                id: TRAIL_LINE,
                type: 'line',
                source: TRAIL_SOURCE,
                layout: { 'line-cap': 'round', 'line-join': 'round' },
                paint: { 'line-width': 3 },
            });
            map.addLayer({
                id: TRAIL_START,
                type: 'circle',
                source: `${TRAIL_SOURCE}-start`,
                paint: { 'circle-radius': 3.5, 'circle-stroke-width': 2 },
            });
        }

        map.setPaintProperty(TRAIL_CASING, 'line-color', casing);
        map.setPaintProperty(TRAIL_LINE, 'line-gradient', [
            'interpolate',
            ['linear'],
            ['line-progress'],
            0,
            faint,
            1,
            color,
        ]);
        map.setPaintProperty(TRAIL_START, 'circle-color', casing);
        map.setPaintProperty(TRAIL_START, 'circle-stroke-color', faint);
        // Colors depend on the theme, so they are reapplied when it changes.
    }, [map, coordinates, resolvedAppearance]);

    const recenter = useCallback(() => {
        map?.easeTo({ center: [longitude, latitude], zoom, duration: 500 });
    }, [map, latitude, longitude, zoom]);

    const fitTrail = useCallback(() => {
        if (map === null) {
            return;
        }

        const bounds = new maplibregl.LngLatBounds();
        coordinates.forEach((c) => bounds.extend(c));
        map.fitBounds(bounds, { padding: 48, maxZoom: 15, duration: 500 });
    }, [map, coordinates]);

    return (
        <div
            className={cn(
                'relative h-full w-full overflow-hidden bg-surface-2',
                className,
            )}
        >
            <div ref={containerRef} className="sam-map h-full w-full" />
            {status === 'loading' && <MapLoading />}
            {status === 'unavailable' && (
                <MapUnavailable latitude={latitude} longitude={longitude} />
            )}
            {map !== null && (
                <MapControls
                    map={map}
                    onRecenter={recenter}
                    onFit={coordinates.length > 1 ? fitTrail : undefined}
                    fitLabel="Ver recorrido"
                />
            )}
        </div>
    );
}
