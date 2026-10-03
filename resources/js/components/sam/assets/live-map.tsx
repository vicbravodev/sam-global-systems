import maplibregl from 'maplibre-gl';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import {
    MapControls,
    MapLoading,
    MapUnavailable,
} from '@/components/sam/map/map-controls';
import {
    clusterSignature,
    createClusterMarker,
    createUnitMarker,
    isMoving,
    speedLine,
    STATUS_URGENCY,
    unitSignature,
    updateClusterMarker,
    updateUnitMarker,
} from '@/components/sam/map/markers';
import type { UnitMarkerState } from '@/components/sam/map/markers';
import { useSamMap } from '@/components/sam/map/use-sam-map';
import { relativeLabel } from '@/lib/time';
import type { AssetMarker } from '@/types/assets';

// Fallback view when the fleet has no positions yet: frame México, not all
// of North America.
const FALLBACK_CENTER: [number, number] = [-102, 23.8];
const FALLBACK_ZOOM = 4.6;

// Two markers closer than this (projected pixels) collapse into one cluster
// bubble at the current zoom. Also the grid cell size, which keeps grouping
// O(n) instead of comparing every pair.
const CLUSTER_PIXEL_RADIUS = 38;

// Markers this far outside the viewport are still drawn, so a short pan
// reveals them already in place; beyond it they are culled from the DOM.
const VIEWPORT_MARGIN = 0.35;

// When a cluster's members sit on the same coordinate, zooming never
// separates them, so they fan out in a ring (spiderfy) instead.
const SPIDER_RADIUS = 30;

// Unit code plates appear from this zoom, while few enough are on screen.
const LABEL_ZOOM = 11;
const LABEL_MAX_ON_SCREEN = 120;

// Positions arrive in batches every few seconds; markers glide to the new
// point over this long instead of jumping, well under the feed interval.
const GLIDE_MS = 1200;
const GLIDE_MAX_MARKERS = 400;

type LngLat = [number, number];

interface Cluster {
    /** Stable id from the member ids, so markers are diffed, not rebuilt. */
    key: string;
    longitude: number;
    latitude: number;
    x: number;
    y: number;
    members: AssetMarker[];
}

interface Rendered {
    marker: maplibregl.Marker;
    signature: string;
    kind: 'unit' | 'cluster';
}

function prefersReducedMotion(): boolean {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function easeOutCubic(t: number): number {
    return 1 - Math.pow(1 - t, 3);
}

function unitState(
    asset: AssetMarker,
    statusLabels: Record<string, string>,
    selected: boolean,
): UnitMarkerState {
    const status = statusLabels[asset.status] ?? asset.status;

    return {
        status: asset.status,
        heading: asset.heading,
        moving: isMoving(asset),
        label: asset.code ?? asset.name,
        ariaLabel: `${asset.code ? `${asset.code} · ` : ''}${asset.name} · ${status}`,
        selected,
    };
}

interface LiveMapProps {
    markers: AssetMarker[];
    statusLabels: Record<string, string>;
    selectedId?: number | null;
    onSelect: (id: number | null) => void;
    /** Card anchored above the selected unit. */
    renderCallout?: (asset: AssetMarker) => ReactNode;
    /** Bump to fly to the selected unit (e.g. picked from a list). */
    focusRequest?: number;
}

export function LiveMap({
    markers,
    statusLabels,
    selectedId = null,
    onSelect,
    renderCallout,
    focusRequest = 0,
}: LiveMapProps) {
    const containerRef = useRef<HTMLDivElement | null>(null);
    const { map, status } = useSamMap(containerRef, {
        center: FALLBACK_CENTER,
        zoom: FALLBACK_ZOOM,
        maxZoom: 18,
    });

    const renderedRef = useRef<Map<string, Rendered>>(new Map());
    const spiderKeyRef = useRef<string | null>(null);
    const didFitRef = useRef(false);
    const [hoveredId, setHoveredId] = useState<number | null>(null);

    // Latest props live in refs so the redraw (driven by map events) always
    // sees fresh data without re-subscribing.
    const markersRef = useRef(markers);
    const statusLabelsRef = useRef(statusLabels);
    const selectedRef = useRef(selectedId);
    const onSelectRef = useRef(onSelect);

    // Where each asset is DRAWN right now; trails the target during a glide.
    const displayRef = useRef<Map<number, LngLat>>(new Map());
    const glideFrameRef = useRef<number | null>(null);
    // Assigned below; lets marker handlers created inside redraw call it.
    const redrawRef = useRef<() => void>(() => {});

    // Floating overlays (hover tooltip, selected callout) follow their unit
    // by writing a transform directly: no React render per map frame.
    const calloutRef = useRef<HTMLDivElement | null>(null);
    const tooltipRef = useRef<HTMLDivElement | null>(null);
    const hoveredRef = useRef<number | null>(null);

    useEffect(() => {
        markersRef.current = markers;
        statusLabelsRef.current = statusLabels;
        selectedRef.current = selectedId;
        onSelectRef.current = onSelect;
        hoveredRef.current = hoveredId;
    });

    const positionOverlays = useCallback(() => {
        if (map === null) {
            return;
        }

        const place = (el: HTMLDivElement | null, id: number | null) => {
            if (el === null) {
                return;
            }

            const at = id !== null ? displayRef.current.get(id) : undefined;

            if (at === undefined) {
                el.style.visibility = 'hidden';

                return;
            }

            const p = map.project(at);
            el.style.visibility = 'visible';
            el.style.transform = `translate(${Math.round(p.x)}px, ${Math.round(p.y)}px)`;
        };

        place(calloutRef.current, selectedRef.current);
        place(
            tooltipRef.current,
            hoveredRef.current !== selectedRef.current
                ? hoveredRef.current
                : null,
        );
    }, [map]);

    const redraw = useCallback(() => {
        if (map === null) {
            return;
        }

        const container = map.getContainer();
        const width = container.clientWidth;
        const height = container.clientHeight;
        const marginX = width * VIEWPORT_MARGIN;
        const marginY = height * VIEWPORT_MARGIN;
        const labels = statusLabelsRef.current;
        const selected = selectedRef.current;
        const display = displayRef.current;
        const rendered = renderedRef.current;

        // 1. Project once, cull what is far off screen.
        const clusters: Cluster[] = [];
        const grid = new Map<string, Cluster[]>();
        const cell = CLUSTER_PIXEL_RADIUS;
        let onScreen = 0;

        for (const source of markersRef.current) {
            const drawn = display.get(source.id);
            const asset = drawn
                ? { ...source, longitude: drawn[0], latitude: drawn[1] }
                : source;
            const p = map.project([asset.longitude, asset.latitude]);

            if (
                p.x < -marginX ||
                p.y < -marginY ||
                p.x > width + marginX ||
                p.y > height + marginY
            ) {
                continue;
            }

            if (p.x >= 0 && p.y >= 0 && p.x <= width && p.y <= height) {
                onScreen++;
            }

            // The selected unit is never swallowed by a cluster.
            if (asset.id === selected) {
                clusters.push({
                    key: String(asset.id),
                    longitude: asset.longitude,
                    latitude: asset.latitude,
                    x: p.x,
                    y: p.y,
                    members: [asset],
                });

                continue;
            }

            // 2. Grid clustering: only the 3x3 neighbouring cells can hold a
            // cluster anchor within the radius.
            const cx = Math.floor(p.x / cell);
            const cy = Math.floor(p.y / cell);
            let target: Cluster | undefined;

            for (let dx = -1; dx <= 1 && !target; dx++) {
                for (let dy = -1; dy <= 1 && !target; dy++) {
                    target = grid
                        .get(`${cx + dx}:${cy + dy}`)
                        ?.find((c) => Math.hypot(c.x - p.x, c.y - p.y) < cell);
                }
            }

            if (target) {
                target.members.push(asset);
            } else {
                const created: Cluster = {
                    key: '',
                    longitude: asset.longitude,
                    latitude: asset.latitude,
                    x: p.x,
                    y: p.y,
                    members: [asset],
                };
                clusters.push(created);
                const k = `${cx}:${cy}`;
                grid.set(k, [...(grid.get(k) ?? []), created]);
            }
        }

        for (const c of clusters) {
            if (c.key !== '') {
                continue;
            }

            c.key = c.members
                .map((m) => m.id)
                .sort((a, b) => a - b)
                .join('-');

            if (c.members.length > 1) {
                // Bubble at the members' centroid, not at the first one.
                c.longitude =
                    c.members.reduce((sum, m) => sum + m.longitude, 0) /
                    c.members.length;
                c.latitude =
                    c.members.reduce((sum, m) => sum + m.latitude, 0) /
                    c.members.length;
            }
        }

        const showLabels =
            map.getZoom() >= LABEL_ZOOM && onScreen <= LABEL_MAX_ON_SCREEN;
        container.toggleAttribute('data-labels', showLabels);

        // 3. Upsert: move what exists, patch what changed, build what is new.
        const live = new Set<string>();
        const spiderKey = spiderKeyRef.current;

        const upsertUnit = (key: string, asset: AssetMarker, at: LngLat) => {
            live.add(key);
            const state = unitState(asset, labels, asset.id === selected);
            const signature = unitSignature(state);
            const existing = rendered.get(key);

            if (existing && existing.kind === 'unit') {
                existing.marker.setLngLat(at);

                if (existing.signature !== signature) {
                    updateUnitMarker(existing.marker.getElement(), state);
                    existing.signature = signature;
                }

                return;
            }

            existing?.marker.remove();
            const el = createUnitMarker(state, true);
            el.addEventListener('click', (event) => {
                event.stopPropagation();
                onSelectRef.current(asset.id);
            });
            el.addEventListener('mouseenter', () => setHoveredId(asset.id));
            el.addEventListener('mouseleave', () =>
                setHoveredId((current) =>
                    current === asset.id ? null : current,
                ),
            );
            el.addEventListener('focus', () => setHoveredId(asset.id));
            el.addEventListener('blur', () => setHoveredId(null));

            rendered.set(key, {
                kind: 'unit',
                signature,
                marker: new maplibregl.Marker({ element: el })
                    .setLngLat(at)
                    .addTo(map),
            });
        };

        for (const cluster of clusters) {
            const [asset] = cluster.members;

            if (cluster.members.length === 1 && asset) {
                upsertUnit(cluster.key, asset, [
                    asset.longitude,
                    asset.latitude,
                ]);

                continue;
            }

            if (cluster.key === spiderKey) {
                const anchor = map.project([
                    cluster.longitude,
                    cluster.latitude,
                ]);

                cluster.members.forEach((asset, index) => {
                    const angle =
                        (2 * Math.PI * index) / cluster.members.length -
                        Math.PI / 2;
                    const fanned = map.unproject([
                        anchor.x + Math.cos(angle) * SPIDER_RADIUS,
                        anchor.y + Math.sin(angle) * SPIDER_RADIUS,
                    ]);
                    upsertUnit(`spider-${asset.id}`, asset, [
                        fanned.lng,
                        fanned.lat,
                    ]);
                });

                continue;
            }

            live.add(cluster.key);
            const statuses = cluster.members.map((m) => m.status);
            const signature = clusterSignature(statuses);
            const worst = [...cluster.members].sort(
                (a, b) => STATUS_URGENCY[b.status] - STATUS_URGENCY[a.status],
            );
            const aria = `${cluster.members.length} unidades: ${worst
                .slice(0, 6)
                .map((m) => m.code ?? m.name)
                .join(', ')}${cluster.members.length > 6 ? '…' : ''}`;
            const existing = rendered.get(cluster.key);
            const at: LngLat = [cluster.longitude, cluster.latitude];

            if (existing && existing.kind === 'cluster') {
                existing.marker.setLngLat(at);

                if (existing.signature !== signature) {
                    updateClusterMarker(
                        existing.marker.getElement(),
                        statuses,
                        aria,
                    );
                    existing.signature = signature;
                }

                continue;
            }

            existing?.marker.remove();
            const el = createClusterMarker(statuses, aria);
            el.addEventListener('click', (event) => {
                event.stopPropagation();
                const [first] = cluster.members;

                if (!first) {
                    return;
                }

                const samePoint = cluster.members.every(
                    (m) =>
                        Math.abs(m.longitude - first.longitude) < 1e-6 &&
                        Math.abs(m.latitude - first.latitude) < 1e-6,
                );

                if (samePoint || map.getZoom() >= map.getMaxZoom() - 0.5) {
                    spiderKeyRef.current = cluster.key;
                    redrawRef.current();

                    return;
                }

                spiderKeyRef.current = null;
                const bounds = new maplibregl.LngLatBounds();
                cluster.members.forEach((m) =>
                    bounds.extend([m.longitude, m.latitude]),
                );
                map.fitBounds(bounds, {
                    padding: 96,
                    maxZoom: Math.min(map.getZoom() + 4, map.getMaxZoom()),
                    duration: 500,
                });
            });

            rendered.set(cluster.key, {
                kind: 'cluster',
                signature,
                marker: new maplibregl.Marker({ element: el })
                    .setLngLat(at)
                    .addTo(map),
            });
        }

        if (spiderKey !== null && !clusters.some((c) => c.key === spiderKey)) {
            spiderKeyRef.current = null;
        }

        rendered.forEach((entry, key) => {
            if (!live.has(key)) {
                entry.marker.remove();
                rendered.delete(key);
            }
        });

        positionOverlays();
    }, [map, positionOverlays]);

    useEffect(() => {
        redrawRef.current = redraw;
    }, [redraw]);

    // Map events: re-cluster when the viewport settles, keep overlays glued
    // while it moves, close things on a click on empty map.
    useEffect(() => {
        if (map === null) {
            return;
        }

        const onMoveEnd = () => redrawRef.current();
        const onZoomStart = () => {
            spiderKeyRef.current = null;
        };
        const onMapClick = () => onSelectRef.current(null);

        map.on('moveend', onMoveEnd);
        map.on('move', positionOverlays);
        map.on('zoomstart', onZoomStart);
        map.on('click', onMapClick);

        const rendered = renderedRef.current;

        return () => {
            map.off('moveend', onMoveEnd);
            map.off('move', positionOverlays);
            map.off('zoomstart', onZoomStart);
            map.off('click', onMapClick);

            if (glideFrameRef.current !== null) {
                cancelAnimationFrame(glideFrameRef.current);
                glideFrameRef.current = null;
            }

            rendered.forEach((entry) => entry.marker.remove());
            rendered.clear();
        };
    }, [map, positionOverlays]);

    // New positions: glide moved markers from where they are drawn to where
    // they are now; re-cluster once, when the glide lands.
    useEffect(() => {
        if (map === null) {
            return;
        }

        const display = displayRef.current;
        const glides: { id: number; from: LngLat; to: LngLat }[] = [];
        const liveIds = new Set<number>();

        for (const asset of markers) {
            liveIds.add(asset.id);
            const to: LngLat = [asset.longitude, asset.latitude];
            const from = display.get(asset.id);

            if (from === undefined) {
                display.set(asset.id, to);
            } else if (from[0] !== to[0] || from[1] !== to[1]) {
                glides.push({ id: asset.id, from, to });
            }
        }

        display.forEach((_, id) => {
            if (!liveIds.has(id)) {
                display.delete(id);
            }
        });

        if (glideFrameRef.current !== null) {
            cancelAnimationFrame(glideFrameRef.current);
            glideFrameRef.current = null;
        }

        if (
            glides.length === 0 ||
            glides.length > GLIDE_MAX_MARKERS ||
            prefersReducedMotion()
        ) {
            glides.forEach(({ id, to }) => display.set(id, to));
            redrawRef.current();
        } else {
            const rendered = renderedRef.current;
            const start = performance.now();

            const step = (now: number): void => {
                const t = Math.min(1, (now - start) / GLIDE_MS);
                const k = easeOutCubic(t);

                for (const { id, from, to } of glides) {
                    const at: LngLat = [
                        from[0] + (to[0] - from[0]) * k,
                        from[1] + (to[1] - from[1]) * k,
                    ];
                    display.set(id, at);
                    // Only a unit drawn on its own follows frame by frame.
                    rendered.get(String(id))?.marker.setLngLat(at);
                }

                positionOverlays();

                if (t < 1) {
                    glideFrameRef.current = requestAnimationFrame(step);
                } else {
                    glideFrameRef.current = null;
                    redrawRef.current();
                }
            };

            glideFrameRef.current = requestAnimationFrame(step);
        }

        if (!didFitRef.current && markers.length > 0) {
            didFitRef.current = true;
            const bounds = new maplibregl.LngLatBounds();
            markers.forEach((a) => bounds.extend([a.longitude, a.latitude]));
            map.fitBounds(bounds, { padding: 72, maxZoom: 13, duration: 0 });
        }
    }, [map, markers, positionOverlays]);

    // Selection and hover change marker state and overlays, not positions.
    useEffect(() => {
        redrawRef.current();
    }, [selectedId, statusLabels]);

    useEffect(() => {
        positionOverlays();
    }, [hoveredId, positionOverlays]);

    // Fly to the selection when asked to (picked from a list), unless it is
    // already comfortably in view.
    useEffect(() => {
        if (
            map === null ||
            focusRequest === 0 ||
            selectedRef.current === null
        ) {
            return;
        }

        const at = displayRef.current.get(selectedRef.current);

        if (at === undefined) {
            return;
        }

        const p = map.project(at);
        const { clientWidth: w, clientHeight: h } = map.getContainer();
        const inView =
            p.x > w * 0.15 && p.x < w * 0.85 && p.y > h * 0.2 && p.y < h * 0.85;

        if (!inView || map.getZoom() < 10) {
            map.easeTo({
                center: at,
                zoom: Math.max(map.getZoom(), 13),
                duration: prefersReducedMotion() ? 0 : 600,
            });
        }
    }, [map, focusRequest]);

    const fitFleet = useCallback(() => {
        if (map === null || markersRef.current.length === 0) {
            return;
        }

        const bounds = new maplibregl.LngLatBounds();
        markersRef.current.forEach((a) =>
            bounds.extend([a.longitude, a.latitude]),
        );
        map.fitBounds(bounds, { padding: 72, maxZoom: 13, duration: 500 });
    }, [map]);

    const selected =
        selectedId !== null
            ? (markers.find((m) => m.id === selectedId) ?? null)
            : null;
    const hovered =
        hoveredId !== null && hoveredId !== selectedId
            ? (markers.find((m) => m.id === hoveredId) ?? null)
            : null;

    return (
        <div className="relative h-full w-full overflow-hidden bg-surface-2">
            <div ref={containerRef} className="sam-map h-full w-full" />

            {status === 'loading' && <MapLoading />}
            {status === 'unavailable' && <MapUnavailable />}

            {map !== null && (
                <MapControls
                    map={map}
                    onFit={markers.length > 0 ? fitFleet : undefined}
                    fitLabel="Ver toda la flota"
                />
            )}

            {/* Hover tooltip: who is this, is it moving, how fresh. */}
            <div
                ref={tooltipRef}
                className="pointer-events-none absolute top-0 left-0 z-20"
                style={{ visibility: 'hidden' }}
                aria-hidden="true"
            >
                {hovered && (
                    <div className="-translate-x-1/2 -translate-y-[calc(100%+18px)] rounded-md border border-border bg-surface-1 px-2.5 py-1.5 whitespace-nowrap shadow-md motion-safe:animate-[sam-copilot-in_var(--motion-fast)_var(--ease-out)]">
                        <div className="text-xs font-semibold text-fg-1">
                            {hovered.code ? `${hovered.code} · ` : ''}
                            {hovered.name}
                        </div>
                        <div className="mt-0.5 flex items-center gap-1.5 text-2xs text-fg-3">
                            <span>
                                {statusLabels[hovered.status] ?? hovered.status}
                            </span>
                            <span aria-hidden="true">·</span>
                            <span className="tabular-nums">
                                {speedLine(hovered)}
                            </span>
                            <span aria-hidden="true">·</span>
                            <span>{relativeLabel(hovered.recordedAt)}</span>
                        </div>
                    </div>
                )}
            </div>

            {/* Selected unit callout, rendered by the page. */}
            <div
                ref={calloutRef}
                className="pointer-events-none absolute top-0 left-0 z-30"
                style={{ visibility: 'hidden' }}
            >
                {selected && renderCallout && (
                    <div className="pointer-events-auto w-72 -translate-x-1/2 -translate-y-[calc(100%+18px)] motion-safe:animate-[sam-copilot-in_var(--motion-normal)_var(--ease-out)]">
                        {renderCallout(selected)}
                    </div>
                )}
            </div>
        </div>
    );
}
