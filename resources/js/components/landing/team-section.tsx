import { lazy } from 'react';
import { DeferredDemo } from '@/components/landing/deferred-demo';

const MonitorInbox = lazy(() =>
    import('@/components/landing/monitor-inbox').then((m) => ({
        default: m.MonitorInbox,
    })),
);

/* El monitorista: casos, no alertas. */
export function TeamSection() {
    return (
        <section id="equipo" className="scroll-mt-16">
            <div className="mx-auto max-w-7xl px-5 py-24 sm:px-8 lg:py-32">
                <div className="max-w-3xl">
                    <h2 className="text-3xl font-semibold tracking-display text-balance sm:text-4xl lg:text-5xl">
                        Tu equipo atiende casos, no alertas.
                    </h2>
                    <p className="mt-6 max-w-xl text-lg leading-relaxed text-brand-ink-2">
                        SAM hace la primera revisión y le entrega a tu
                        monitorista solo lo que importa: ordenado por urgencia,
                        con el tiempo de respuesta corriendo y la evidencia ya
                        reunida. Pruébalo: toma el caso del pánico.
                    </p>
                </div>
                <DeferredDemo
                    demo={MonitorInbox}
                    className="mt-14"
                    reserve="min-h-236 sm:min-h-209 lg:min-h-109"
                />
                <ul className="mt-12 grid gap-x-12 gap-y-6 text-base leading-relaxed text-brand-ink-2 md:grid-cols-3">
                    <li>
                        <span className="font-semibold text-brand-ink">
                            Nadie trabaja el mismo caso dos veces.
                        </span>{' '}
                        Quien lo toma se lo queda y los demás lo ven.
                    </li>
                    <li>
                        <span className="font-semibold text-brand-ink">
                            Los tiempos de respuesta los pones tú.
                        </span>{' '}
                        Por prioridad, y SAM escala si se vencen.
                    </li>
                    <li>
                        <span className="font-semibold text-brand-ink">
                            Tu criterio mejora a SAM.
                        </span>{' '}
                        Cuando tu equipo corrige un veredicto, la IA lo toma en
                        cuenta.
                    </li>
                </ul>
            </div>
        </section>
    );
}
