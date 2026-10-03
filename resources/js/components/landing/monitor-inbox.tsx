import { Check, Hand, PhoneMissed, Sparkles } from 'lucide-react';
import {
    AnimatePresence,
    LayoutGroup,
    motion,
    useInView,
    useReducedMotion,
} from 'motion/react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

type Severity = 'critical' | 'high' | 'medium';

type Case = {
    id: string;
    title: string;
    unit: string;
    place: string;
    severity: Severity;
    /** Segundos de SLA restantes al cargar. */
    sla: number;
    verdict: string;
    confidence: number;
    checks: { text: string; warn?: boolean }[];
};

/* Casos de ejemplo de la misma noche que cuenta el resto del landing. */
const CASES: [Case, ...Case[]] = [
    {
        id: 'panic',
        title: 'Botón de pánico',
        unit: 'T-600',
        place: 'Carr. 40D km 182, cerca de Reynosa',
        severity: 'critical',
        sla: 134,
        verdict: 'Emergencia probable',
        confidence: 0.91,
        checks: [
            { text: 'Unidad detenida en el acotamiento desde las 02:12' },
            {
                text: 'Cámara de cabina: el operador no está en su asiento',
                warn: true,
            },
            {
                text: 'Llamada de verificación: sin respuesta en 2 intentos',
                warn: true,
            },
            { text: 'El conductor no tiene eventos en los últimos 90 días' },
        ],
    },
    {
        id: 'silent',
        title: 'Dejó de reportar en movimiento',
        unit: 'T-087',
        place: 'Carr. 85, Sabinas Hidalgo',
        severity: 'high',
        sla: 492,
        verdict: 'Posible inhibidor de señal',
        confidence: 0.74,
        checks: [
            { text: 'Iba a 82 km/h cuando perdió la señal' },
            {
                text: 'No es una zona sin cobertura conocida',
                warn: true,
            },
            { text: 'Última posición compartida con tu equipo de rastreo' },
        ],
    },
    {
        id: 'hours',
        title: 'Movimiento fuera de horario',
        unit: 'T-214',
        place: 'Patio Apodaca',
        severity: 'medium',
        sla: 1320,
        verdict: 'Requiere confirmar con el patio',
        confidence: 0.62,
        checks: [
            { text: 'Arrancó a las 03:05 sin viaje programado' },
            { text: 'Recorrió 400 m y se detuvo dentro del patio' },
        ],
    },
];

const SEVERITY: Record<Severity, { label: string; dot: string; text: string }> =
    {
        critical: {
            label: 'Crítico',
            dot: 'bg-brand-alert',
            text: 'text-brand-alert',
        },
        high: {
            label: 'Alto',
            dot: 'bg-brand-high',
            text: 'text-brand-high-ink',
        },
        medium: {
            label: 'Medio',
            dot: 'bg-brand-medium',
            text: 'text-brand-medium-ink',
        },
    };

function mmss(total: number) {
    const s = Math.max(0, total);

    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

const EASE = [0.16, 1, 0.3, 1] as const;

/**
 * La bandeja del monitorista, en miniatura: casos (no alertas) ordenados por
 * urgencia, con el SLA corriendo y la evidencia ya reunida. Tomar un caso lo
 * mueve a "Míos" y detiene la escalación, como en el producto.
 */
export function MonitorInbox() {
    const reduce = useReducedMotion() ?? false;
    const ref = useRef<HTMLDivElement>(null);
    const inView = useInView(ref, { amount: 0.3 });
    const [elapsed, setElapsed] = useState(0);
    const [selected, setSelected] = useState(CASES[0].id);
    const [mine, setMine] = useState<string[]>([]);
    const [tab, setTab] = useState<'queue' | 'mine'>('queue');

    // El reloj solo corre mientras la bandeja está en pantalla.
    useEffect(() => {
        if (!inView) {
            return;
        }

        const id = window.setInterval(() => setElapsed((e) => e + 1), 1000);

        return () => window.clearInterval(id);
    }, [inView]);

    const queue = CASES.filter((c) => !mine.includes(c.id));
    const mineCases = CASES.filter((c) => mine.includes(c.id));
    const visible = tab === 'queue' ? queue : mineCases;
    const current = CASES.find((c) => c.id === selected) ?? CASES[0];
    const taken = mine.includes(current.id);

    const take = (id: string) => {
        setMine((m) => [...m, id]);
        setTab('mine');
    };

    return (
        <div
            ref={ref}
            className="theme-light overflow-hidden rounded-[1.25rem] border border-brand-line bg-white shadow-[0_30px_60px_-30px_rgba(0,94,125,0.3)]"
        >
            <LayoutGroup>
                <div className="grid lg:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
                    {/* Lista */}
                    <div className="border-b border-brand-line lg:border-r lg:border-b-0">
                        <div
                            role="tablist"
                            aria-label="Bandeja"
                            className="flex gap-1 border-b border-brand-line px-3 pt-3"
                        >
                            {(
                                [
                                    ['queue', 'Por atender', queue.length],
                                    ['mine', 'Míos', mineCases.length],
                                ] as const
                            ).map(([key, label, count]) => (
                                <button
                                    key={key}
                                    type="button"
                                    role="tab"
                                    aria-selected={tab === key}
                                    onClick={() => setTab(key)}
                                    className={cn(
                                        'relative px-3 pt-1.5 pb-3 text-base font-medium transition-colors',
                                        tab === key
                                            ? 'text-brand-ink'
                                            : 'text-brand-ink-3 hover:text-brand-ink',
                                    )}
                                >
                                    {label}
                                    <span className="ml-1.5 rounded-full bg-brand-mist px-1.5 py-0.5 text-xs text-brand-petrol tabular-nums">
                                        {count}
                                    </span>
                                    {tab === key && (
                                        <motion.span
                                            layoutId="inbox-tab"
                                            className="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-brand-teal"
                                        />
                                    )}
                                </button>
                            ))}
                        </div>

                        <ul className="min-h-[19rem] p-2">
                            <AnimatePresence initial={false} mode="popLayout">
                                {visible.map((c) => {
                                    const left = c.sla - elapsed;
                                    const isMine = mine.includes(c.id);

                                    return (
                                        <motion.li
                                            key={c.id}
                                            layout={!reduce}
                                            initial={
                                                reduce
                                                    ? false
                                                    : { opacity: 0, x: 24 }
                                            }
                                            animate={{ opacity: 1, x: 0 }}
                                            exit={{
                                                opacity: 0,
                                                x: -24,
                                                transition: { duration: 0.2 },
                                            }}
                                            transition={{
                                                duration: 0.45,
                                                ease: EASE,
                                            }}
                                        >
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setSelected(c.id)
                                                }
                                                className={cn(
                                                    'grid w-full grid-cols-[auto_1fr_auto] items-center gap-3 rounded-xl px-3 py-3 text-left transition-colors',
                                                    selected === c.id
                                                        ? 'bg-brand-mist'
                                                        : 'hover:bg-brand-paper',
                                                )}
                                            >
                                                <span
                                                    className={cn(
                                                        'size-2.5 rounded-full',
                                                        SEVERITY[c.severity]
                                                            .dot,
                                                    )}
                                                />
                                                <span className="min-w-0">
                                                    <span className="block truncate text-base font-semibold text-brand-ink">
                                                        {c.title}, {c.unit}
                                                    </span>
                                                    <span className="block truncate text-sm text-brand-ink-3">
                                                        {c.place}
                                                    </span>
                                                </span>
                                                <span
                                                    className={cn(
                                                        'text-right text-sm tabular-nums',
                                                        isMine
                                                            ? 'text-brand-aqua'
                                                            : left < 180
                                                              ? 'font-semibold text-brand-alert'
                                                              : 'text-brand-ink-2',
                                                    )}
                                                >
                                                    {isMine ? (
                                                        'Tuyo'
                                                    ) : (
                                                        <>
                                                            {mmss(left)}
                                                            <span className="block text-xs font-normal text-brand-ink-3">
                                                                para atender
                                                            </span>
                                                        </>
                                                    )}
                                                </span>
                                            </button>
                                        </motion.li>
                                    );
                                })}
                            </AnimatePresence>
                            {visible.length === 0 && (
                                <li className="px-3 py-10 text-center text-base text-brand-ink-3">
                                    {tab === 'mine'
                                        ? 'Aún no tomas ningún caso.'
                                        : 'No queda nada por atender.'}
                                </li>
                            )}
                        </ul>

                        <p className="border-t border-brand-line px-5 py-3 text-sm text-brand-ink-3">
                            <span className="font-semibold text-brand-ink tabular-nums">
                                131
                            </span>{' '}
                            alertas revisadas y descartadas por SAM esta noche,
                            con su registro.
                        </p>
                    </div>

                    {/* Detalle con la evidencia armada */}
                    <AnimatePresence mode="wait" initial={false}>
                        <motion.div
                            key={current.id}
                            initial={reduce ? false : { opacity: 0, y: 8 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{
                                opacity: 0,
                                transition: { duration: 0.12 },
                            }}
                            transition={{ duration: 0.35, ease: EASE }}
                            className="flex flex-col p-6 sm:p-7"
                        >
                            <p
                                className={cn(
                                    'text-sm font-semibold',
                                    SEVERITY[current.severity].text,
                                )}
                            >
                                {SEVERITY[current.severity].label}
                            </p>
                            <h3 className="mt-1 text-xl font-semibold tracking-tight text-brand-ink">
                                {current.title}, {current.unit}
                            </h3>
                            <p className="text-base text-brand-ink-3">
                                {current.place}
                            </p>

                            <div className="mt-5 flex items-center gap-3 rounded-xl bg-brand-mist px-4 py-3">
                                <Sparkles
                                    className="size-4 shrink-0 text-brand-petrol"
                                    strokeWidth={2}
                                />
                                <p className="flex-1 text-base text-brand-ink">
                                    <span className="font-semibold">
                                        {current.verdict}
                                    </span>
                                </p>
                                <span className="text-sm text-brand-petrol tabular-nums">
                                    {Math.round(current.confidence * 100)} % de
                                    certeza
                                </span>
                            </div>

                            <p className="mt-6 text-sm font-medium text-brand-ink-2">
                                Lo que SAM ya revisó por ti
                            </p>
                            <ul className="mt-3 space-y-2.5">
                                {current.checks.map((check, i) => (
                                    <motion.li
                                        key={check.text}
                                        initial={
                                            reduce
                                                ? false
                                                : { opacity: 0, x: -6 }
                                        }
                                        animate={{ opacity: 1, x: 0 }}
                                        transition={{
                                            delay: 0.1 + i * 0.08,
                                            ease: EASE,
                                        }}
                                        className="flex gap-2.5 text-base leading-snug text-brand-ink-2"
                                    >
                                        {check.warn ? (
                                            <PhoneMissed
                                                className="mt-0.5 size-4 shrink-0 text-brand-alert"
                                                strokeWidth={2}
                                            />
                                        ) : (
                                            <Check
                                                className="mt-0.5 size-4 shrink-0 text-brand-aqua"
                                                strokeWidth={2.5}
                                            />
                                        )}
                                        {check.text}
                                    </motion.li>
                                ))}
                            </ul>

                            <div className="mt-auto pt-7">
                                <AnimatePresence mode="wait" initial={false}>
                                    {taken ? (
                                        <motion.p
                                            key="taken"
                                            initial={
                                                reduce
                                                    ? false
                                                    : {
                                                          opacity: 0,
                                                          scale: 0.96,
                                                      }
                                            }
                                            animate={{ opacity: 1, scale: 1 }}
                                            className="flex items-center gap-2 rounded-xl border border-brand-aqua/40 bg-brand-aqua/10 px-4 py-3 text-base text-brand-ink"
                                        >
                                            <Check
                                                className="size-4 text-brand-aqua"
                                                strokeWidth={2.5}
                                            />
                                            Es tuyo. SAM detuvo la escalación y
                                            sigue sumando evidencia.
                                        </motion.p>
                                    ) : (
                                        <motion.button
                                            key="take"
                                            type="button"
                                            onClick={() => take(current.id)}
                                            whileTap={{ scale: 0.97 }}
                                            className="flex h-12 w-full items-center justify-center gap-2 rounded-full bg-brand-teal text-md font-medium text-white shadow-[0_8px_20px_-8px_rgba(0,128,159,0.6)] transition-colors hover:bg-brand-petrol focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-teal"
                                        >
                                            <Hand
                                                className="size-4"
                                                strokeWidth={2}
                                            />
                                            Tomar el caso
                                        </motion.button>
                                    )}
                                </AnimatePresence>
                            </div>
                        </motion.div>
                    </AnimatePresence>
                </div>
            </LayoutGroup>
        </div>
    );
}
