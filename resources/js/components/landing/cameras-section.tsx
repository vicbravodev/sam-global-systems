import { lazy } from 'react';
import { DeferredDemo } from '@/components/landing/deferred-demo';

const CabinVision = lazy(() =>
    import('@/components/landing/cabin-vision').then((m) => ({
        default: m.CabinVision,
    })),
);

/* Visión: SAM mira las cámaras. */
export function CamerasSection() {
    return (
        <section
            id="camaras"
            className="scroll-mt-16 border-y border-brand-line bg-white"
        >
            <div className="mx-auto max-w-7xl px-5 py-24 sm:px-8 lg:py-32">
                <div className="max-w-3xl">
                    <h2 className="text-3xl font-semibold tracking-display text-balance sm:text-4xl lg:text-5xl">
                        SAM revisa las cámaras antes que tú.
                    </h2>
                    <p className="mt-6 max-w-xl text-lg leading-relaxed text-brand-ink-2">
                        Pide las fotos y el video de los segundos alrededor de
                        cada evento, mira dentro y fuera de la cabina y te lo
                        resume en una frase.
                    </p>
                </div>
                <DeferredDemo
                    demo={CabinVision}
                    className="mt-14"
                    reserve="min-h-139 sm:min-h-157 md:min-h-189 lg:min-h-100 xl:min-h-118"
                />
            </div>
        </section>
    );
}
