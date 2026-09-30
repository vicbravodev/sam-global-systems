import { MapPin, Phone } from 'lucide-react';
import { motion, useInView, useReducedMotion } from 'motion/react';
import { useRef } from 'react';

const EASE = [0.16, 1, 0.3, 1] as const;

/**
 * Lo que recibe el jefe de turno a las 02:15: primero la llamada, luego el
 * resumen por WhatsApp con lo que SAM ya verificó. Las piezas entran en orden
 * cuando el teléfono aparece en pantalla.
 */
export function AlertPhone() {
    const ref = useRef<HTMLDivElement>(null);
    const inView = useInView(ref, { once: true, amount: 0.45 });
    const reduce = useReducedMotion() ?? false;
    const show = inView || reduce;

    const enter = (delay: number) => ({
        initial: reduce ? false : { opacity: 0, y: 18, scale: 0.97 },
        animate: show ? { opacity: 1, y: 0, scale: 1 } : undefined,
        transition: {
            delay,
            type: 'spring' as const,
            stiffness: 200,
            damping: 24,
        },
    });

    return (
        <div
            ref={ref}
            className="relative mx-auto w-full max-w-[21rem] rounded-[2.75rem] border border-brand-line bg-brand-ink p-2.5 shadow-[0_40px_80px_-30px_rgba(0,94,125,0.45)]"
        >
            <div className="overflow-hidden rounded-[2.25rem] bg-brand-mist">
                <div className="flex items-center justify-between px-6 pt-3 pb-2 text-xs font-semibold text-brand-ink">
                    <span className="tabular-nums">2:15</span>
                    <span className="h-5 w-20 rounded-full bg-brand-ink" />
                    <span className="w-8" />
                </div>

                <div className="space-y-3 px-3.5 pt-2 pb-6">
                    <motion.div
                        {...enter(0.15)}
                        className="flex items-center gap-3 rounded-2xl bg-brand-ink p-3.5 text-white"
                    >
                        <img
                            src="/images/brand/sam-emblem.png"
                            alt=""
                            className="size-10 rounded-full bg-white p-1"
                        />
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-semibold">
                                SAM Emergencias
                            </p>
                            <p className="text-xs text-white/60">
                                Llamando, 02:15
                            </p>
                        </div>
                        <span className="grid size-8 animate-pulse place-items-center rounded-full bg-brand-aqua motion-reduce:animate-none">
                            <Phone className="size-3.5" strokeWidth={2.25} />
                        </span>
                    </motion.div>

                    <motion.p
                        {...enter(0.55)}
                        className="pt-2 text-center text-xs text-brand-ink-3"
                    >
                        WhatsApp
                    </motion.p>

                    <motion.div
                        {...enter(0.8)}
                        className="mr-6 rounded-2xl rounded-tl-md bg-white p-3.5 text-sm leading-relaxed text-brand-ink shadow-sm"
                    >
                        <p className="font-semibold text-brand-alert">
                            Emergencia en la T-600
                        </p>
                        <p className="mt-1.5 text-brand-ink-2">
                            Botón de pánico activado en la Carr. 40D, km 182,
                            cerca de Reynosa. La unidad está detenida y el
                            operador no contestó la llamada de verificación.
                        </p>
                        <p className="mt-2 flex items-center gap-1.5 text-xs text-brand-ink-3">
                            <MapPin className="size-3.5" strokeWidth={2} />
                            Ubicación y video incluidos en el caso
                        </p>
                        <p className="mt-2 text-right text-2xs text-brand-ink-3">
                            02:15
                        </p>
                    </motion.div>

                    <motion.div {...enter(1.15)} className="mr-6 grid gap-1.5">
                        <span className="rounded-xl bg-white py-2.5 text-center text-sm font-medium text-brand-teal shadow-sm">
                            Ver el caso
                        </span>
                        <span className="flex items-center justify-center gap-1.5 rounded-xl bg-white py-2.5 text-center text-sm font-medium text-brand-teal shadow-sm">
                            <Phone className="size-3.5" strokeWidth={2} />
                            Llamar al operador
                        </span>
                    </motion.div>
                </div>
            </div>
            {!reduce && show && (
                <motion.span
                    aria-hidden="true"
                    className="pointer-events-none absolute -inset-3 rounded-[3.25rem] border-2 border-brand-alert/40"
                    initial={{ opacity: 0.9, scale: 0.98 }}
                    animate={{ opacity: 0, scale: 1.06 }}
                    transition={{ duration: 1.4, ease: EASE, delay: 0.15 }}
                />
            )}
        </div>
    );
}
