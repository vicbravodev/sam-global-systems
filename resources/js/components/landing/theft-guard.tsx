import { motion, useReducedMotion } from 'motion/react';
import type { TargetAndTransition, Transition } from 'motion/react';
import type { ReactNode } from 'react';

const GUARDS: { title: string; body: string; art: ReactNode }[] = [
    {
        title: 'Deja de reportar en plena carretera',
        body: 'Si una unidad se calla mientras avanza, puede ser un inhibidor de señal. SAM abre un caso de inmediato.',
        art: <SignalArt />,
    },
    {
        title: 'Se mueve fuera de horario',
        body: 'Una unidad que arranca a las 3 de la mañana sin viaje programado no pasa desapercibida.',
        art: <ClockArt />,
    },
    {
        title: 'Pierde el GPS en ruta',
        body: 'Cuando la posición se pierde con la unidad en movimiento, SAM te avisa antes de que se enfríe el rastro.',
        art: <PinArt />,
    },
    {
        title: 'Se detiene donde no debe',
        body: 'Una parada larga fuera de tus zonas autorizadas pasa a revisión de tu equipo.',
        art: <StopArt />,
    },
];

/* Cuatro señales de robo, cada una con una animación mínima que la explica.
   Con reduced-motion los dibujos quedan fijos. */
export function TheftGuard() {
    return (
        <ul className="grid gap-x-16 gap-y-12 sm:grid-cols-2">
            {GUARDS.map((g, i) => (
                <motion.li
                    key={g.title}
                    className="grid grid-cols-[4.5rem_1fr] gap-5"
                    initial={{ opacity: 0, y: 14 }}
                    whileInView={{ opacity: 1, y: 0 }}
                    viewport={{ once: true, amount: 0.5 }}
                    transition={{
                        duration: 0.6,
                        delay: (i % 2) * 0.12,
                        ease: [0.16, 1, 0.3, 1],
                    }}
                >
                    <span className="grid size-18 place-items-center rounded-2xl bg-night-surface">
                        {g.art}
                    </span>
                    <div>
                        <h3 className="text-xl font-semibold tracking-tight text-white">
                            {g.title}
                        </h3>
                        <p className="mt-2 text-base leading-relaxed text-night-muted">
                            {g.body}
                        </p>
                    </div>
                </motion.li>
            ))}
        </ul>
    );
}

function useLoop() {
    const reduce = useReducedMotion();

    return (
        keyframes: TargetAndTransition,
        duration = 3,
        delay = 0,
    ): { animate?: TargetAndTransition; transition?: Transition } =>
        reduce
            ? {}
            : {
                  animate: keyframes,
                  transition: {
                      duration,
                      delay,
                      repeat: Infinity,
                      ease: 'easeInOut' as const,
                  },
              };
}

/* Barras de señal que se apagan una por una mientras la unidad avanza. */
function SignalArt() {
    const loop = useLoop();

    return (
        <svg viewBox="0 0 48 48" className="size-11" aria-hidden="true">
            {[0, 1, 2, 3].map((i) => (
                <motion.rect
                    key={i}
                    x={8 + i * 9}
                    y={32 - i * 7}
                    width={6}
                    height={8 + i * 7}
                    rx={1.5}
                    fill="#24b6a1"
                    {...loop(
                        { opacity: [1, 1, 0.15, 0.15, 1] },
                        3.2,
                        (3 - i) * 0.25,
                    )}
                />
            ))}
            <motion.path
                d="M6,44 L42,44"
                stroke="#ff5b63"
                strokeWidth={2}
                strokeLinecap="round"
                initial={{ pathLength: 0 }}
                {...loop({ pathLength: [0, 0, 1, 1, 0] }, 3.2)}
            />
        </svg>
    );
}

/* Reloj a las 3:00 con una unidad que empieza a moverse. */
function ClockArt() {
    const loop = useLoop();

    return (
        <svg viewBox="0 0 48 48" className="size-11" aria-hidden="true">
            <circle
                cx={20}
                cy={20}
                r={13}
                fill="none"
                stroke="#7fa3b3"
                strokeWidth={2}
            />
            <path
                d="M20,20 L20,11"
                stroke="#dbe8ee"
                strokeWidth={2}
                strokeLinecap="round"
            />
            <path
                d="M20,20 L27,20"
                stroke="#dbe8ee"
                strokeWidth={2}
                strokeLinecap="round"
            />
            <path
                d="M8,42 L44,42"
                stroke="#1f5a73"
                strokeWidth={2}
                strokeDasharray="3 3"
            />
            <motion.circle
                cy={42}
                r={3.5}
                fill="#f2b64a"
                initial={{ cx: 10 }}
                {...loop({ cx: [10, 10, 40, 40] }, 3.4)}
            />
        </svg>
    );
}

/* Pin de GPS que se desvanece sobre la ruta. */
function PinArt() {
    const loop = useLoop();

    return (
        <svg viewBox="0 0 48 48" className="size-11" aria-hidden="true">
            <path
                d="M4,40 C14,30 22,40 30,30 S40,22 44,16"
                fill="none"
                stroke="#1f5a73"
                strokeWidth={2}
                strokeDasharray="3 3"
            />
            <motion.g
                {...loop(
                    { opacity: [1, 1, 0.1, 0.1, 1], y: [0, 0, -3, -3, 0] },
                    3,
                )}
            >
                <path
                    d="M24,6 C18,6 14,10 14,15 C14,22 24,30 24,30 C24,30 34,22 34,15 C34,10 30,6 24,6 Z"
                    fill="#24b6a1"
                />
                <circle cx={24} cy={15} r={3.5} fill="#061c2a" />
            </motion.g>
        </svg>
    );
}

/* Unidad detenida fuera de la zona autorizada (círculo punteado). */
function StopArt() {
    const loop = useLoop();

    return (
        <svg viewBox="0 0 48 48" className="size-11" aria-hidden="true">
            <circle
                cx={18}
                cy={22}
                r={13}
                fill="#24b6a1"
                fillOpacity={0.08}
                stroke="#24b6a1"
                strokeWidth={1.5}
                strokeDasharray="3 3"
            />
            <motion.circle
                cx={38}
                cy={34}
                r={9}
                fill="none"
                stroke="#ff5b63"
                strokeWidth={1.5}
                initial={{ scale: 0.4, opacity: 0.9 }}
                style={{ transformOrigin: '38px 34px' }}
                {...loop({ scale: [0.4, 1.3], opacity: [0.9, 0] }, 1.8)}
            />
            <circle cx={38} cy={34} r={4} fill="#ff5b63" />
        </svg>
    );
}
