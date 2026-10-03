import { motion, useReducedMotion } from 'motion/react';
import { CopilotDemo } from '@/components/landing/copilot-demo';
import { DEMO_HREF } from '@/components/landing/copy';
import { EASE } from '@/components/landing/lib';
import { PrimaryButton } from '@/components/landing/primary-button';

/* El Copiloto en vivo. Está arriba del pliegue, así que no se difiere. */
export function HeroSection({ authed }: { authed: boolean }) {
    return (
        <section
            id="copiloto"
            className="relative scroll-mt-16 overflow-hidden"
        >
            <div
                aria-hidden="true"
                className="pointer-events-none absolute top-0 right-0 h-full w-[60%] bg-[radial-gradient(60%_55%_at_60%_45%,var(--color-brand-mist),transparent)]"
            />
            <div className="relative mx-auto grid max-w-7xl grid-cols-1 items-center gap-12 px-5 pt-12 pb-20 sm:px-8 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] lg:gap-16 lg:pt-16 lg:pb-28">
                <HeroCopy authed={authed} />
                <motion.div
                    className="min-w-0"
                    initial={{ opacity: 0, y: 24 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{
                        duration: 0.9,
                        delay: 0.25,
                        ease: EASE,
                    }}
                >
                    <CopilotDemo />
                </motion.div>
            </div>
        </section>
    );
}

function HeroCopy({ authed }: { authed: boolean }) {
    const reduce = useReducedMotion();
    const lines = ['Pregúntale', 'a tu flota.'];

    return (
        <div className="min-w-0">
            <h1 className="text-5xl font-semibold tracking-poster sm:text-6xl">
                {lines.map((line, i) => (
                    <span key={line} className="block overflow-hidden pb-2">
                        <motion.span
                            className="block"
                            initial={reduce ? false : { y: '105%' }}
                            animate={{ y: 0 }}
                            transition={{
                                duration: 0.9,
                                delay: 0.05 + i * 0.1,
                                ease: EASE,
                            }}
                        >
                            {line}
                        </motion.span>
                    </span>
                ))}
            </h1>
            <motion.p
                className="mt-6 max-w-md text-lg leading-relaxed text-brand-ink-2"
                initial={reduce ? false : { opacity: 0, y: 12 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.7, delay: 0.3, ease: EASE }}
            >
                SAM vigila tus unidades día y noche, investiga cada alerta y te
                responde con los datos reales de tu operación.
            </motion.p>
            <motion.div
                className="mt-10 flex flex-wrap items-center gap-6"
                initial={reduce ? false : { opacity: 0, y: 12 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.7, delay: 0.4, ease: EASE }}
            >
                {!authed && (
                    <PrimaryButton href={DEMO_HREF} large>
                        Pedir una demo
                    </PrimaryButton>
                )}
                <a
                    href="#guardia"
                    className="text-md font-medium text-brand-petrol underline decoration-brand-teal/40 underline-offset-4 transition-colors hover:decoration-brand-teal"
                >
                    Ver una noche con SAM
                </a>
            </motion.div>
        </div>
    );
}
