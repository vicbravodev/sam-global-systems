import { lazy } from 'react';
import { DeferredDemo } from '@/components/landing/deferred-demo';

const NightWatch = lazy(() =>
    import('@/components/landing/night-watch').then((m) => ({
        default: m.NightWatch,
    })),
);
const TheftGuard = lazy(() =>
    import('@/components/landing/theft-guard').then((m) => ({
        default: m.TheftGuard,
    })),
);

/* Guardia nocturna y antirrobo. */
export function NightSection() {
    return (
        <section
            id="guardia"
            className="scroll-mt-16 bg-brand-night text-white"
        >
            <div className="mx-auto max-w-7xl px-5 py-24 sm:px-8 lg:py-32">
                <div className="max-w-2xl">
                    <h2 className="text-3xl font-semibold tracking-display text-balance sm:text-4xl lg:text-5xl">
                        Mientras duermes, SAM hace la guardia.
                    </h2>
                    <p className="mt-6 max-w-xl text-lg leading-relaxed text-night-muted">
                        Una noche de ejemplo de una flota de 48 unidades: 147
                        eventos revisados y una sola llamada, la que importaba.
                        Arrastra la barra para recorrerla.
                    </p>
                </div>
                <DeferredDemo
                    demo={NightWatch}
                    className="mt-14"
                    reserve="min-h-111 sm:min-h-140 md:min-h-175 lg:min-h-134 xl:min-h-153"
                />

                <div
                    id="antirrobo"
                    className="mt-28 scroll-mt-24 border-t border-white/10 pt-20"
                >
                    <h2 className="max-w-2xl text-3xl font-semibold tracking-display text-balance sm:text-4xl">
                        Y si una unidad se comporta raro, SAM lo nota.
                    </h2>
                    <p className="mt-5 max-w-xl text-lg leading-relaxed text-night-muted">
                        No espera a que alguien reporte un robo. Vigila las
                        señales que lo anticipan y, cuando la unidad vuelve a la
                        normalidad, cierra el caso solo.
                    </p>
                    <DeferredDemo
                        demo={TheftGuard}
                        className="mt-14"
                        reserve="min-h-164 sm:min-h-110 md:min-h-80 lg:min-h-60 xl:min-h-54"
                    />
                </div>
            </div>
        </section>
    );
}
