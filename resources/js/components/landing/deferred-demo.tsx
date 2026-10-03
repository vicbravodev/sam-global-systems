import { useInView } from 'motion/react';
import { Suspense, useRef } from 'react';
import type { ComponentType, LazyExoticComponent } from 'react';

export interface DeferredDemoProps {
    /** Demo cargada con `React.lazy`: su JS sale del bundle inicial. */
    demo: LazyExoticComponent<ComponentType>;
    /** `min-h` por breakpoint, medido sobre la demo real, para no mover el layout. */
    reserve: string;
    className?: string;
}

/**
 * Monta una demo de la landing sólo cuando se acerca al viewport. En SSR y en
 * el primer render del cliente pinta el mismo hueco reservado, así que la
 * hidratación coincide; sin JS el texto de la sección sigue completo.
 */
export function DeferredDemo({
    demo: Demo,
    reserve,
    className,
}: DeferredDemoProps) {
    const ref = useRef<HTMLDivElement>(null);
    const near = useInView(ref, { once: true, margin: '1200px 0px' });
    const placeholder = <div aria-hidden="true" className={reserve} />;

    return (
        <div ref={ref} className={className}>
            {near ? (
                <Suspense fallback={placeholder}>
                    <Demo />
                </Suspense>
            ) : (
                placeholder
            )}
        </div>
    );
}
