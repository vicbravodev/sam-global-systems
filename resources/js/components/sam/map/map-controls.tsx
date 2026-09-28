import { Compass, LocateFixed, Maximize, Minus, Plus } from 'lucide-react';
import type maplibregl from 'maplibre-gl';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

function ControlButton({
    label,
    onClick,
    children,
}: {
    label: string;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            title={label}
            className="grid size-8 cursor-pointer place-items-center text-fg-2 transition-colors duration-(--motion-fast) outline-none hover:bg-surface-2 hover:text-fg-1 focus-visible:bg-surface-2 focus-visible:text-fg-1 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset active:bg-surface-3"
        >
            {children}
        </button>
    );
}

function Group({ children }: { children: ReactNode }) {
    return (
        <div className="flex flex-col divide-y divide-border overflow-hidden rounded-md border border-border bg-surface-1 shadow-sm">
            {children}
        </div>
    );
}

interface Props {
    map: maplibregl.Map;
    /** Frame everything the map is showing (fleet, trail). */
    onFit?: () => void;
    fitLabel?: string;
    /** Re-center on the subject of a detail map. */
    onRecenter?: () => void;
    className?: string;
}

/**
 * Zoom / fit / north controls drawn with the UI's own components instead of
 * the stock maplibre ones. The compass only appears once the map is rotated.
 */
export function MapControls({
    map,
    onFit,
    fitLabel = 'Encuadrar todo',
    onRecenter,
    className,
}: Props) {
    const [bearing, setBearing] = useState(() => map.getBearing());

    useEffect(() => {
        const onRotate = () => setBearing(map.getBearing());
        map.on('rotate', onRotate);

        return () => {
            map.off('rotate', onRotate);
        };
    }, [map]);

    return (
        <div
            className={cn(
                'pointer-events-auto absolute top-3 right-3 z-10 flex flex-col gap-2',
                className,
            )}
        >
            <Group>
                <ControlButton label="Acercar" onClick={() => map.zoomIn()}>
                    <Plus className="size-4" />
                </ControlButton>
                <ControlButton label="Alejar" onClick={() => map.zoomOut()}>
                    <Minus className="size-4" />
                </ControlButton>
            </Group>

            {(onFit || onRecenter) && (
                <Group>
                    {onFit && (
                        <ControlButton label={fitLabel} onClick={onFit}>
                            <Maximize className="size-3.5" />
                        </ControlButton>
                    )}
                    {onRecenter && (
                        <ControlButton label="Centrar" onClick={onRecenter}>
                            <LocateFixed className="size-4" />
                        </ControlButton>
                    )}
                </Group>
            )}

            {Math.abs(bearing) > 0.5 && (
                <Group>
                    <ControlButton
                        label="Orientar al norte"
                        onClick={() => map.resetNorthPitch()}
                    >
                        <Compass
                            className="size-4"
                            style={{ transform: `rotate(${-bearing}deg)` }}
                        />
                    </ControlButton>
                </Group>
            )}
        </div>
    );
}

/** Placeholder with the final map's footprint while tiles load. */
export function MapLoading() {
    return (
        <div className="absolute inset-0 z-10 grid place-items-center bg-surface-2">
            <span className="flex items-center gap-2 rounded-md border border-border bg-surface-1 px-3 py-1.5 text-xs text-fg-3 shadow-xs">
                <span className="size-1.5 rounded-full bg-fg-3 motion-safe:animate-pulse" />
                Cargando mapa…
            </span>
        </div>
    );
}

/** No WebGL or tiles unreachable: the coordinates still carry the answer. */
export function MapUnavailable({
    latitude,
    longitude,
}: {
    latitude?: number;
    longitude?: number;
}) {
    return (
        <div className="absolute inset-0 grid place-items-center bg-surface-2 p-4 text-center">
            <span className="rounded-md border border-border bg-surface-1 px-3 py-1.5 text-xs text-fg-3">
                Mapa no disponible en este navegador
                {latitude !== undefined && longitude !== undefined && (
                    <>
                        {' · '}
                        <span className="font-mono tabular-nums">
                            {latitude.toFixed(5)}, {longitude.toFixed(5)}
                        </span>
                    </>
                )}
            </span>
        </div>
    );
}
