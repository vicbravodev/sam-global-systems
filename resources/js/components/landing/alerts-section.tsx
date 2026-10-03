import { lazy } from 'react';
import type { ReactNode } from 'react';
import { DeferredDemo } from '@/components/landing/deferred-demo';

const AlertPhone = lazy(() =>
    import('@/components/landing/alert-phone').then((m) => ({
        default: m.AlertPhone,
    })),
);

/* Avisos. */
export function AlertsSection() {
    return (
        <section id="avisos" className="scroll-mt-16">
            <div className="mx-auto grid max-w-7xl grid-cols-1 items-center gap-16 px-5 py-24 sm:px-8 lg:grid-cols-[1fr_0.8fr] lg:py-32">
                <div>
                    <h2 className="max-w-xl text-3xl font-semibold tracking-display text-balance sm:text-4xl lg:text-5xl">
                        Te avisa por donde sí vas a contestar.
                    </h2>
                    <dl className="mt-12 grid max-w-lg gap-8">
                        <Notice title="Primero confirma con el operador.">
                            SAM le llama y él marca 1 si la emergencia es real o
                            2 si fue un error. Si nadie contesta, escala.
                        </Notice>
                        <Notice title="Una emergencia te llama por teléfono.">
                            A cualquier hora, y lo importante te llega por
                            WhatsApp con lo que SAM ya verificó.
                        </Notice>
                        <Notice title="Respondes sin abrir nada.">
                            Contesta el mensaje con SI, NO o ESC para confirmar,
                            descartar o escalar.
                        </Notice>
                        <Notice title="Si un canal falla, usa el siguiente.">
                            Llamada, WhatsApp o SMS, y confirma que el aviso de
                            verdad llegó.
                        </Notice>
                    </dl>
                </div>
                <DeferredDemo demo={AlertPhone} reserve="min-h-126" />
            </div>
        </section>
    );
}

function Notice({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-lg font-semibold">{title}</dt>
            <dd className="mt-1.5 text-base leading-relaxed text-brand-ink-2">
                {children}
            </dd>
        </div>
    );
}
