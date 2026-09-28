import maplibregl from 'maplibre-gl';
import type { MapOptions } from 'maplibre-gl';
import { useEffect, useRef, useState } from 'react';
import type { RefObject } from 'react';
import {
    applyPalette,
    loadBaseStyle,
    readPalette,
    themedStyle,
} from '@/components/sam/map/basemap';
import { useAppearance } from '@/hooks/use-appearance';
import 'maplibre-gl/dist/maplibre-gl.css';

export type SamMapStatus = 'loading' | 'ready' | 'unavailable';

type Options = Omit<MapOptions, 'container' | 'style'>;

/**
 * One MapLibre map on the themed SAM basemap. Owns creation, the theme
 * repaint and teardown; returns the map once its style has loaded.
 *
 * `unavailable` covers no WebGL (old GPU, remote desktop) and an unreachable
 * tile server: callers render the coordinates instead of an endless loader.
 * `options` are read once, at creation.
 */
export function useSamMap(
    containerRef: RefObject<HTMLDivElement | null>,
    options: Options,
): { map: maplibregl.Map | null; status: SamMapStatus } {
    const { resolvedAppearance } = useAppearance();
    const [map, setMap] = useState<maplibregl.Map | null>(null);
    const [status, setStatus] = useState<SamMapStatus>('loading');
    const optionsRef = useRef(options);

    useEffect(() => {
        const container = containerRef.current;

        if (container === null) {
            return;
        }

        let cancelled = false;
        let instance: maplibregl.Map | null = null;

        loadBaseStyle()
            .then((base) => {
                if (cancelled) {
                    return;
                }

                try {
                    instance = new maplibregl.Map({
                        attributionControl: { compact: true },
                        ...optionsRef.current,
                        container,
                        style: themedStyle(base, readPalette()),
                    });
                } catch {
                    setStatus('unavailable');

                    return;
                }

                const created = instance;

                created.on('load', () => {
                    // The compact attribution opens itself on wide maps;
                    // start it folded, it stays one click away.
                    container
                        .querySelector('.maplibregl-ctrl-attrib')
                        ?.classList.remove('maplibregl-compact-show');
                    container
                        .querySelector('.maplibregl-ctrl-attrib')
                        ?.removeAttribute('open');

                    if (!cancelled) {
                        setMap(created);
                        setStatus('ready');
                    }
                });

                created.on('error', () => {
                    if (!cancelled && !created.loaded()) {
                        setStatus('unavailable');
                    }
                });
            })
            .catch(() => {
                if (!cancelled) {
                    setStatus('unavailable');
                }
            });

        return () => {
            cancelled = true;
            instance?.remove();
            setMap(null);
            setStatus('loading');
        };
    }, [containerRef]);

    // Theme switch: repaint in place, keeping the app's own layers.
    useEffect(() => {
        if (map !== null) {
            applyPalette(map, readPalette());
        }
    }, [map, resolvedAppearance]);

    return { map, status };
}
