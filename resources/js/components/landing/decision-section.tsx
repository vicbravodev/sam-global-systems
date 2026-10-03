import { BellRing, Ear, PhoneCall, ScanSearch } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import { EASE } from '@/components/landing/lib';

const STEPS = [
    {
        icon: Ear,
        title: 'Escucha',
        body: 'Recibe cada evento de tus unidades en el momento en que pasa.',
    },
    {
        icon: ScanSearch,
        title: 'Investiga',
        body: 'Revisa ubicación, video de las cámaras y el historial del conductor.',
    },
    {
        icon: PhoneCall,
        title: 'Confirma',
        body: 'Si hace falta, llama al operador antes de molestar a nadie más.',
    },
    {
        icon: BellRing,
        title: 'Avisa',
        body: 'Solo a la persona correcta, con el caso ya armado. Lo demás queda registrado.',
    },
];

/* Cómo decide. */
export function DecisionSection() {
    return (
        <section id="como-decide" className="scroll-mt-16">
            <div className="mx-auto max-w-7xl px-5 py-24 sm:px-8 lg:py-32">
                <h2 className="max-w-3xl text-3xl font-semibold tracking-display text-balance sm:text-4xl lg:text-5xl">
                    Revisa cada alerta como lo haría tu mejor monitorista.
                </h2>
                <DecisionPath />
                <p className="mt-16 max-w-2xl border-l-2 border-brand-alert pl-5 text-lg leading-relaxed text-brand-ink-2">
                    Un botón de pánico, un choque o una volcadura no esperan a
                    nadie:{' '}
                    <span className="font-semibold text-brand-ink">
                        se abren como emergencia en el mismo momento
                    </span>
                    , mientras SAM sigue reuniendo la evidencia.
                </p>
            </div>
        </section>
    );
}

/* Los cuatro pasos son una secuencia real, así que van numerados y unidos por
   una ruta que se dibuja al entrar en pantalla. */
function DecisionPath() {
    const reduce = useReducedMotion();

    return (
        <div className="relative mt-16">
            <svg
                aria-hidden="true"
                viewBox="0 0 1000 40"
                preserveAspectRatio="none"
                className="absolute top-6 left-[12.5%] hidden h-10 w-[75%] -translate-y-1/2 lg:block"
            >
                <motion.path
                    d="M0,20 C120,4 210,36 333,20 S546,4 666,20 S880,36 1000,20"
                    fill="none"
                    stroke="var(--color-brand-teal)"
                    strokeWidth={2}
                    strokeDasharray="1 0"
                    vectorEffect="non-scaling-stroke"
                    initial={reduce ? false : { pathLength: 0 }}
                    whileInView={{ pathLength: 1 }}
                    viewport={{ once: true, amount: 0.6 }}
                    transition={{ duration: 1.6, ease: EASE }}
                />
            </svg>
            <ol className="relative grid gap-12 sm:grid-cols-2 lg:grid-cols-4 lg:gap-8">
                {STEPS.map((step, i) => (
                    <motion.li
                        key={step.title}
                        className="lg:text-center"
                        initial={reduce ? false : { opacity: 0, y: 16 }}
                        whileInView={{ opacity: 1, y: 0 }}
                        viewport={{ once: true, amount: 0.6 }}
                        transition={{
                            duration: 0.6,
                            delay: 0.15 + i * 0.28,
                            ease: EASE,
                        }}
                    >
                        <span className="relative inline-grid size-12 place-items-center rounded-full border border-brand-line bg-white text-brand-teal shadow-sm">
                            <step.icon className="size-5" strokeWidth={1.75} />
                            <span className="absolute -top-1 -right-1 grid size-5 place-items-center rounded-full bg-brand-ink text-2xs font-semibold text-white">
                                {i + 1}
                            </span>
                        </span>
                        <h3 className="mt-5 text-xl font-semibold tracking-tight">
                            {step.title}
                        </h3>
                        <p className="mt-2 text-base leading-relaxed text-brand-ink-2 lg:mx-auto lg:max-w-[16rem]">
                            {step.body}
                        </p>
                    </motion.li>
                ))}
            </ol>
        </div>
    );
}
