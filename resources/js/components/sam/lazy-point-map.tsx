import { lazy, Suspense } from 'react';
import type { ComponentProps } from 'react';
import { MapLoading } from '@/components/sam/map/map-controls';
import { cn } from '@/lib/utils';
import type { PointMap as PointMapComponent } from './point-map';

// maplibre-gl (~270 kB gz) only loads when a detail page actually draws its
// map card: the rest of the page paints without waiting for it.
const LazyMap = lazy(() =>
    import('./point-map').then((module) => ({ default: module.PointMap })),
);

type PointMapProps = ComponentProps<typeof PointMapComponent>;

/**
 * {@link PointMapComponent} loaded on demand. While the chunk downloads it
 * shows the same "Cargando mapa…" frame the map shows while its tiles load,
 * so the card keeps its size and look.
 */
export function PointMap(props: PointMapProps) {
    return (
        <Suspense
            fallback={
                <div
                    className={cn(
                        'relative h-full w-full overflow-hidden bg-surface-2',
                        props.className,
                    )}
                >
                    <MapLoading />
                </div>
            }
        >
            <LazyMap {...props} />
        </Suspense>
    );
}
