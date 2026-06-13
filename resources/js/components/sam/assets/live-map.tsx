import maplibregl from 'maplibre-gl';
import { useEffect, useRef, useState } from 'react';
import { useAppearance } from '@/hooks/use-appearance';
import type { ResolvedAppearance } from '@/hooks/use-appearance';
import type { AssetMarker, AssetStatusValue } from '@/types/assets';
import 'maplibre-gl/dist/maplibre-gl.css';

// OpenFreeMap serves free vector tiles with no API key (approved with the
// MapLibre dependency for F3). The style is light; in dark mode we recolor the
// tile canvas with a CSS filter (markers are HTML overlays, so they stay
// correctly colored — see DARK_CANVAS_FILTER / the container className).
const MAP_STYLE_URL = 'https://tiles.openfreemap.org/styles/liberty';

// Inverting + hue-rotating the light vector tiles yields a dark basemap that
// agrees with the dark UI (C-14) without needing a second keyed tile provider.
const DARK_CANVAS_FILTER =
    'invert(1) hue-rotate(180deg) brightness(0.92) contrast(0.95)';

// Fallback view when the fleet has no positions yet: frame México, not all
// of North America.
const FALLBACK_CENTER: [number, number] = [-102, 23.8];
const FALLBACK_ZOOM = 4.6;

// Two markers whose projected pixel distance is below this threshold collapse
// into one cluster bubble at the current zoom. Keeps stacked markers reachable
// (C-04) instead of hidden behind whichever one paints last.
const CLUSTER_PIXEL_RADIUS = 34;

// When a cluster's members sit on the exact same coordinate, zooming never
// separates them, so we fan them out in a ring (spiderfy) instead.
const SPIDER_RADIUS = 26;

const STATUS_COLORS: Record<AssetStatusValue, string> = {
    active: 'var(--severity-low)',
    inactive: 'var(--fg-3)',
    offline: 'var(--fg-3)',
    alert: 'var(--severity-high)',
    critical: 'var(--severity-critical)',
    maintenance: 'var(--severity-medium)',
};

// Cluster bubbles take the most urgent status present in the group so a stack
// hiding a critical asset still reads as critical.
const STATUS_SEVERITY: Record<AssetStatusValue, number> = {
    critical: 5,
    alert: 4,
    maintenance: 3,
    offline: 2,
    inactive: 1,
    active: 0,
};

interface Cluster {
    /** Stable id derived from the member ids, so React/maplibre can diff. */
    key: string;
    longitude: number;
    latitude: number;
    members: AssetMarker[];
}

function dominantStatus(members: AssetMarker[]): AssetStatusValue {
    return members.reduce<AssetStatusValue>(
        (worst, m) =>
            STATUS_SEVERITY[m.status] > STATUS_SEVERITY[worst]
                ? m.status
                : worst,
        members[0].status,
    );
}

function markerLabel(
    asset: AssetMarker,
    statusLabels: Record<string, string>,
): string {
    const status = statusLabels[asset.status] ?? asset.status;
    const code = asset.code ? `${asset.code} · ` : '';

    return `${code}${asset.name} · ${status}`;
}

function buildSingleMarker(
    asset: AssetMarker,
    statusLabels: Record<string, string>,
    onSelect: (id: number) => void,
): HTMLButtonElement {
    const el = document.createElement('button');
    el.type = 'button';
    el.className =
        'size-3.5 cursor-pointer rounded-full border-2 border-white shadow-md ' +
        'outline-none focus-visible:ring-2 focus-visible:ring-primary ' +
        'focus-visible:ring-offset-1';
    el.style.backgroundColor = STATUS_COLORS[asset.status];
    const label = markerLabel(asset, statusLabels);
    el.title = label;
    el.setAttribute('aria-label', label);
    el.addEventListener('click', (event) => {
        event.stopPropagation();
        onSelect(asset.id);
    });

    return el;
}

function buildClusterMarker(
    cluster: Cluster,
    statusLabels: Record<string, string>,
    onActivate: () => void,
): HTMLButtonElement {
    const el = document.createElement('button');
    el.type = 'button';
    el.className =
        'grid size-7 cursor-pointer place-items-center rounded-full border-2 ' +
        'border-white text-3xs font-semibold text-white shadow-md outline-none ' +
        'focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1';
    el.style.backgroundColor = STATUS_COLORS[dominantStatus(cluster.members)];
    el.textContent = String(cluster.members.length);
    const label = `${cluster.members.length} activos agrupados: ${cluster.members
        .map((m) => m.code ?? m.name)
        .join(', ')}`;
    el.title = label;
    el.setAttribute('aria-label', label);
    el.addEventListener('click', (event) => {
        event.stopPropagation();
        onActivate();
    });

    return el;
}

interface LiveMapProps {
    markers: AssetMarker[];
    statusLabels: Record<string, string>;
    onSelect: (id: number) => void;
}

export function LiveMap({ markers, statusLabels, onSelect }: LiveMapProps) {
    const { resolvedAppearance } = useAppearance();
    const containerRef = useRef<HTMLDivElement | null>(null);
    const canvasWrapRef = useRef<HTMLElement | null>(null);
    const mapRef = useRef<maplibregl.Map | null>(null);
    // Markers maplibre currently has on screen, keyed by cluster key.
    const renderedRef = useRef<Map<string, maplibregl.Marker>>(new Map());
    // Cluster key currently spiderfied (its members shown fanned out), if any.
    const spiderKeyRef = useRef<string | null>(null);
    const didFitRef = useRef(false);
    const [loaded, setLoaded] = useState(false);

    // Latest props/closures live in refs so the redraw routine (driven by map
    // move events) always sees fresh data without re-subscribing.
    const markersRef = useRef(markers);
    const onSelectRef = useRef(onSelect);
    const statusLabelsRef = useRef(statusLabels);
    const appearanceRef = useRef<ResolvedAppearance>(resolvedAppearance);

    // Redraw is assigned inside the init effect and called from prop effects.
    const redrawRef = useRef<() => void>(() => {});

    useEffect(() => {
        markersRef.current = markers;
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
        canvasWrapRef.current = map.getCanvasContainer();

        // Group markers by projected pixel distance at the current zoom, then
        // upsert one DOM marker per cluster (single asset or bubble). Spiderfy
        // is preserved across redraws while its cluster still exists.
        const redraw = (): void => {
            const all = markersRef.current;
            const labels = statusLabelsRef.current;
            const select = onSelectRef.current;
            const rendered = renderedRef.current;

            const clusters: Cluster[] = [];

            all.forEach((asset) => {
                const point = map.project([asset.longitude, asset.latitude]);
                const target = clusters.find((c) => {
                    const cp = map.project([c.longitude, c.latitude]);

                    return (
                        Math.hypot(cp.x - point.x, cp.y - point.y) <
                        CLUSTER_PIXEL_RADIUS
                    );
                });

                if (target) {
                    target.members.push(asset);
                } else {
                    clusters.push({
                        key: '',
                        longitude: asset.longitude,
                        latitude: asset.latitude,
                        members: [asset],
                    });
                }
            });

            clusters.forEach((c) => {
                c.key = c.members
                    .map((m) => m.id)
                    .sort((a, b) => a - b)
                    .join('-');
            });

            const liveKeys = new Set<string>();
            const spiderKey = spiderKeyRef.current;

            clusters.forEach((cluster) => {
                if (cluster.key === spiderKey && cluster.members.length > 1) {
                    // Keep this cluster fanned out: render each member offset
                    // in a ring around the shared anchor.
                    cluster.members.forEach((asset, index) => {
                        const angle =
                            (2 * Math.PI * index) / cluster.members.length;
                        const anchor = map.project([
                            cluster.longitude,
                            cluster.latitude,
                        ]);
                        const fanned = map.unproject([
                            anchor.x + Math.cos(angle) * SPIDER_RADIUS,
                            anchor.y + Math.sin(angle) * SPIDER_RADIUS,
                        ]);
                        const key = `spider-${cluster.key}-${asset.id}`;
                        liveKeys.add(key);

                        if (!rendered.has(key)) {
                            const marker = new maplibregl.Marker({
                                element: buildSingleMarker(asset, labels, (id) =>
                                    select(id),
                                ),
                            })
                                .setLngLat([fanned.lng, fanned.lat])
                                .addTo(map);
                            rendered.set(key, marker);
                        } else {
                            rendered
                                .get(key)!
                                .setLngLat([fanned.lng, fanned.lat]);
                        }
                    });

                    return;
                }

                liveKeys.add(cluster.key);

                if (rendered.has(cluster.key)) {
                    rendered
                        .get(cluster.key)!
                        .setLngLat([cluster.longitude, cluster.latitude]);

                    return;
                }

                const element =
                    cluster.members.length === 1
                        ? buildSingleMarker(
                              cluster.members[0],
                              labels,
                              (id) => select(id),
                          )
                        : buildClusterMarker(cluster, labels, () => {
                              // Try to break the cluster by zooming in; if its
                              // members share a coordinate, zoom won't help, so
                              // spiderfy instead.
                              const samePoint = cluster.members.every(
                                  (m) =>
                                      m.longitude ===
                                          cluster.members[0].longitude &&
                                      m.latitude === cluster.members[0].latitude,
                              );

                              if (samePoint || map.getZoom() >= map.getMaxZoom()) {
                                  spiderKeyRef.current = cluster.key;
                                  redrawRef.current();
                              } else {
                                  spiderKeyRef.current = null;
                                  map.easeTo({
                                      center: [
                                          cluster.longitude,
                                          cluster.latitude,
                                      ],
                                      zoom: Math.min(
                                          map.getZoom() + 2,
                                          map.getMaxZoom(),
                                      ),
                                  });
                              }
                          });

                const marker = new maplibregl.Marker({ element })
                    .setLngLat([cluster.longitude, cluster.latitude])
                    .addTo(map);
                rendered.set(cluster.key, marker);
            });

            // Drop markers whose cluster no longer exists. If the spiderfied
            // cluster vanished, clear the spider state too.
            if (spiderKey !== null && !clusters.some((c) => c.key === spiderKey)) {
                spiderKeyRef.current = null;
            }

            rendered.forEach((marker, key) => {
                if (!liveKeys.has(key)) {
                    marker.remove();
                    rendered.delete(key);
                }
            });
        };

        redrawRef.current = redraw;

        const handleLoad = (): void => {
            setLoaded(true);
            redraw();
        };

        // Re-cluster after the viewport settles (zoom/pan changes pixel
        // distances) and collapse any open spider when the user navigates away.
        const handleMoveEnd = (): void => redraw();
        const collapseSpider = (): void => {
            spiderKeyRef.current = null;
        };

        map.on('load', handleLoad);
        map.on('moveend', handleMoveEnd);
        map.on('zoomstart', collapseSpider);

        const rendered = renderedRef.current;

        return () => {
            rendered.forEach((marker) => marker.remove());
            rendered.clear();
            spiderKeyRef.current = null;
            mapRef.current = null;
            canvasWrapRef.current = null;
            redrawRef.current = () => {};
            map.remove();
        };
    }, []);

    // Re-cluster whenever the asset list changes (live position/status updates).
    useEffect(() => {
        if (mapRef.current === null || !loaded) {
            return;
        }

        redrawRef.current();

        if (!didFitRef.current && markers.length > 0) {
            didFitRef.current = true;
            const bounds = new maplibregl.LngLatBounds();
            markers.forEach((asset) =>
                bounds.extend([asset.longitude, asset.latitude]),
            );
            mapRef.current.fitBounds(bounds, {
                padding: 64,
                maxZoom: 14,
                duration: 0,
            });
        }
    }, [markers, loaded]);

    // Theme-aware basemap (C-14): recolor the tile canvas for dark mode. The
    // HTML markers live outside the canvas container, so their colors stay
    // truthful.
    useEffect(() => {
        appearanceRef.current = resolvedAppearance;
        const wrap = canvasWrapRef.current;

        if (wrap === null) {
            return;
        }

        wrap.style.filter =
            resolvedAppearance === 'dark' ? DARK_CANVAS_FILTER : '';
    }, [resolvedAppearance, loaded]);

    return (
        <div className="relative h-full w-full">
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
