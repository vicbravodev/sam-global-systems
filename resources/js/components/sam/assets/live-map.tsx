import maplibregl from 'maplibre-gl';
import { useEffect, useRef } from 'react';
import type { AssetMarker, AssetStatusValue } from '@/types/assets';
import 'maplibre-gl/dist/maplibre-gl.css';

// OpenFreeMap serves free vector tiles with no API key (approved with the
// MapLibre dependency for F3).
const MAP_STYLE_URL = 'https://tiles.openfreemap.org/styles/liberty';

// Fallback view when the fleet has no positions yet (world view).
const FALLBACK_CENTER: [number, number] = [-99.5, 23.5];
const FALLBACK_ZOOM = 3.5;

const STATUS_COLORS: Record<AssetStatusValue, string> = {
    active: 'var(--severity-low)',
    inactive: 'var(--fg-3)',
    offline: 'var(--fg-3)',
    alert: 'var(--severity-high)',
    critical: 'var(--severity-critical)',
    maintenance: 'var(--severity-medium)',
};

function markerElement(
    asset: AssetMarker,
    statusLabels: Record<string, string>,
    onSelect: (id: number) => void,
): HTMLDivElement {
    const el = document.createElement('div');
    el.className =
        'size-3.5 cursor-pointer rounded-full border-2 border-white shadow-md';
    el.style.backgroundColor = STATUS_COLORS[asset.status];
    el.title = `${asset.name} · ${statusLabels[asset.status] ?? asset.status}`;
    el.addEventListener('click', (event) => {
        event.stopPropagation();
        onSelect(asset.id);
    });

    return el;
}

interface LiveMapProps {
    markers: AssetMarker[];
    statusLabels: Record<string, string>;
    onSelect: (id: number) => void;
}

export function LiveMap({ markers, statusLabels, onSelect }: LiveMapProps) {
    const containerRef = useRef<HTMLDivElement | null>(null);
    const mapRef = useRef<maplibregl.Map | null>(null);
    const markerRefs = useRef<Map<number, maplibregl.Marker>>(new Map());
    const didFitRef = useRef(false);

    // The callbacks live in refs so the marker-sync effect does not have to
    // tear markers down whenever the parent re-renders with a new closure.
    const onSelectRef = useRef(onSelect);
    const statusLabelsRef = useRef(statusLabels);

    useEffect(() => {
        onSelectRef.current = onSelect;
        statusLabelsRef.current = statusLabels;
    });

    useEffect(() => {
        if (containerRef.current === null) {
            return;
        }

        const map = new maplibregl.Map({
            container: containerRef.current,
            style: MAP_STYLE_URL,
            center: FALLBACK_CENTER,
            zoom: FALLBACK_ZOOM,
        });
        map.addControl(new maplibregl.NavigationControl(), 'top-right');
        mapRef.current = map;

        const markerMap = markerRefs.current;

        return () => {
            markerMap.forEach((marker) => marker.remove());
            markerMap.clear();
            mapRef.current = null;
            map.remove();
        };
    }, []);

    // Sync the maplibre markers with the asset list: upsert positions and
    // colors in place, drop stale ones. Never re-fit bounds after the first
    // load so live updates do not yank the operator's viewport. This effect
    // is declared after the init effect, so on mount the map already exists.
    useEffect(() => {
        const map = mapRef.current;

        if (map === null) {
            return;
        }

        const seen = new Set<number>();

        markers.forEach((asset) => {
            seen.add(asset.id);
            const existing = markerRefs.current.get(asset.id);

            if (existing) {
                existing.setLngLat([asset.longitude, asset.latitude]);
                const el = existing.getElement();
                el.style.backgroundColor = STATUS_COLORS[asset.status];
                el.title = `${asset.name} · ${statusLabelsRef.current[asset.status] ?? asset.status}`;

                return;
            }

            const marker = new maplibregl.Marker({
                element: markerElement(asset, statusLabelsRef.current, (id) =>
                    onSelectRef.current(id),
                ),
            })
                .setLngLat([asset.longitude, asset.latitude])
                .addTo(map);

            markerRefs.current.set(asset.id, marker);
        });

        markerRefs.current.forEach((marker, id) => {
            if (!seen.has(id)) {
                marker.remove();
                markerRefs.current.delete(id);
            }
        });

        if (!didFitRef.current && markers.length > 0) {
            didFitRef.current = true;
            const bounds = new maplibregl.LngLatBounds();
            markers.forEach((asset) =>
                bounds.extend([asset.longitude, asset.latitude]),
            );
            map.fitBounds(bounds, { padding: 64, maxZoom: 14, duration: 0 });
        }
    }, [markers]);

    return <div ref={containerRef} className="h-full w-full" />;
}
