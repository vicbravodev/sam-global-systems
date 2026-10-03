import { Pause, Play, RotateCcw } from 'lucide-react';
import {
    animate,
    AnimatePresence,
    motion,
    useInView,
    useMotionValue,
    useMotionValueEvent,
    useReducedMotion,
} from 'motion/react';
import type { AnimationPlaybackControls } from 'motion/react';
import {
    useEffect,
    useEffectEvent,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import { cn } from '@/lib/utils';

/* Minutos desde las 20:00. La noche dura 600 minutos (hasta las 06:00). */
const NIGHT = 600;
const PLAY_SECONDS = 22;
const TOTAL_EVENTS = 147;

type RoadId = 'r57' | 'r40' | 'r85n' | 'r85s' | 'r54';

/* Mapa esquemático del noreste (no a escala): las carreteras por las que
   ruedan las unidades de la flota de ejemplo. */
const ROADS: Record<RoadId, { d: string; name: string }> = {
    r57: { d: 'M232,96 C250,190 236,300 262,384', name: 'Carr. 57' },
    r40: {
        d: 'M262,384 C360,396 430,364 520,352 C640,334 770,284 900,232',
        name: 'Carr. 40D',
    },
    r85n: {
        d: 'M520,352 C540,292 556,232 562,170 C568,110 566,66 562,24',
        name: 'Carr. 85',
    },
    r85s: { d: 'M520,352 C574,414 626,470 668,536', name: 'Carr. 85' },
    r54: { d: 'M262,384 C292,452 318,500 336,556', name: 'Carr. 54' },
};

const CITIES = [
    { name: 'Monclova', x: 232, y: 96, dx: 12, dy: 4 },
    { name: 'Saltillo', x: 262, y: 384, dx: -14, dy: 20, end: true },
    { name: 'Monterrey', x: 520, y: 352, dx: 14, dy: 22 },
    { name: 'Sabinas Hidalgo', x: 562, y: 170, dx: 14, dy: 4 },
    { name: 'Reynosa', x: 900, y: 232, dx: -12, dy: -14, end: true },
    { name: 'Linares', x: 668, y: 536, dx: 14, dy: 0 },
];

const TRUCKS: { road: RoadId; start: number; speed: number }[] = [
    { road: 'r40', start: 0.1, speed: 1.4 },
    { road: 'r40', start: 0.62, speed: 1.1 },
    { road: 'r85n', start: 0.3, speed: 1.6 },
    { road: 'r57', start: 0.55, speed: 1.2 },
    { road: 'r85s', start: 0.2, speed: 1.3 },
    { road: 'r54', start: 0.7, speed: 1.0 },
    { road: 'r40', start: 0.35, speed: 0.9 },
];

type Kind = 'noise' | 'watch' | 'alert';

type NightEvent = {
    at: number;
    road: RoadId;
    along: number;
    kind: Kind;
    title: string;
    outcome: string;
};

const EVENTS: NightEvent[] = [
    {
        at: 35,
        road: 'r40',
        along: 0.42,
        kind: 'noise',
        title: 'Frenado brusco, T-204',
        outcome: 'Tráfico detenido adelante. Descartado.',
    },
    {
        at: 78,
        road: 'r57',
        along: 0.5,
        kind: 'noise',
        title: 'Exceso de velocidad, T-118',
        outcome: 'Registrado en su historial.',
    },
    {
        at: 125,
        road: 'r85s',
        along: 0.4,
        kind: 'noise',
        title: 'Distracción al volante, T-062',
        outcome: 'Evento aislado. Documentado.',
    },
    {
        at: 205,
        road: 'r85n',
        along: 0.62,
        kind: 'watch',
        title: 'Unidad sin señal, T-087',
        outcome: 'Estaba en zona sin cobertura. Reconectó a los 22 min.',
    },
    {
        at: 228,
        road: 'r40',
        along: 0.3,
        kind: 'watch',
        title: 'Botón de pánico, T-214',
        outcome:
            'Las cámaras no mostraron amenazas y el operador confirmó por llamada que fue un error.',
    },
    {
        at: 290,
        road: 'r54',
        along: 0.55,
        kind: 'noise',
        title: 'Frenado brusco, T-175',
        outcome: 'Sin impacto. Descartado.',
    },
    {
        at: 374,
        road: 'r40',
        along: 0.86,
        kind: 'alert',
        title: 'Botón de pánico, T-600',
        outcome:
            'El operador no contestó. SAM llamó a Jorge, jefe de turno, a las 02:15.',
    },
    {
        at: 450,
        road: 'r40',
        along: 0.2,
        kind: 'noise',
        title: 'Parada no programada, T-214',
        outcome: 'Carga de diésel en estación autorizada.',
    },
    {
        at: 540,
        road: 'r85n',
        along: 0.25,
        kind: 'noise',
        title: 'Exceso de velocidad, T-118',
        outcome: 'Registrado en su historial.',
    },
];

function clock(minutes: number) {
    const total = (20 * 60 + Math.round(minutes)) % (24 * 60);

    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

function pingPong(v: number) {
    const m = v % 2;

    return m <= 1 ? m : 2 - m;
}

/**
 * La guardia nocturna de una flota, comprimida en ~20 segundos. Se reproduce
 * sola al entrar en pantalla; la barra de tiempo se puede arrastrar. Las
 * unidades se mueven fuera del ciclo de render (motion value → atributos
 * SVG); solo el conteo de eventos visibles re-renderiza.
 */
export function NightWatch() {
    const reduce = useReducedMotion() ?? false;
    const rootRef = useRef<HTMLDivElement>(null);
    const inView = useInView(rootRef, { once: true, amount: 0.35 });
    const time = useMotionValue(reduce ? NIGHT : 0);
    const controls = useRef<AnimationPlaybackControls | null>(null);
    const roadRefs = useRef<Partial<Record<RoadId, SVGPathElement | null>>>({});
    const truckRefs = useRef<(SVGGElement | null)[]>([]);
    const clockRef = useRef<HTMLSpanElement>(null);
    const reviewedRef = useRef<HTMLSpanElement>(null);
    const rangeRef = useRef<HTMLInputElement>(null);
    const [points, setPoints] = useState<{ x: number; y: number }[]>([]);
    const [passed, setPassed] = useState(reduce ? EVENTS.length : 0);
    const [playing, setPlaying] = useState(false);

    const place = (t: number) => {
        TRUCKS.forEach((truck, i) => {
            const road = roadRefs.current[truck.road];
            const el = truckRefs.current[i];

            if (!road || !el) {
                return;
            }

            const len = road.getTotalLength();
            const p = road.getPointAtLength(
                pingPong(truck.start + (t / NIGHT) * truck.speed * 2) * len,
            );
            el.setAttribute('transform', `translate(${p.x} ${p.y})`);
        });

        if (clockRef.current) {
            clockRef.current.textContent = clock(t);
        }

        if (reviewedRef.current) {
            reviewedRef.current.textContent = String(
                Math.round((t / NIGHT) * TOTAL_EVENTS),
            );
        }

        if (rangeRef.current && document.activeElement !== rangeRef.current) {
            rangeRef.current.value = String(Math.round(t));
        }

        const count = EVENTS.filter((e) => e.at <= t).length;
        setPassed((prev) => (prev === count ? prev : count));
    };

    const placeNow = useEffectEvent(() => place(time.get()));

    // Posiciones fijas de los eventos, medidas sobre las carreteras reales
    // (una sola vez, al montar).
    useLayoutEffect(() => {
        setPoints(
            EVENTS.map((e) => {
                const road = roadRefs.current[e.road];

                if (!road) {
                    return { x: 0, y: 0 };
                }

                const p = road.getPointAtLength(
                    road.getTotalLength() * e.along,
                );

                return { x: p.x, y: p.y };
            }),
        );
        placeNow();
    }, []);

    useMotionValueEvent(time, 'change', place);

    const play = (from?: number) => {
        controls.current?.stop();
        const start = from ?? (time.get() >= NIGHT ? 0 : time.get());
        time.set(start);
        setPlaying(true);
        controls.current = animate(time, NIGHT, {
            duration: ((NIGHT - start) / NIGHT) * PLAY_SECONDS,
            ease: 'linear',
            onComplete: () => setPlaying(false),
        });
    };

    const pause = () => {
        controls.current?.stop();
        setPlaying(false);
    };

    const autoplay = useEffectEvent(() => play(0));

    useEffect(() => {
        if (inView && !reduce) {
            autoplay();
        }

        return () => controls.current?.stop();
    }, [inView, reduce]);

    const log = EVENTS.slice(0, passed).reverse();
    const alerted = EVENTS.slice(0, passed).filter(
        (e) => e.kind === 'alert',
    ).length;

    return (
        <div ref={rootRef} className="grid gap-8 lg:grid-cols-[1fr_22rem]">
            <div className="relative overflow-hidden rounded-[1.25rem] border border-white/10 bg-night-surface">
                <svg
                    viewBox="0 0 960 560"
                    className="block h-auto w-full"
                    role="img"
                    aria-label="Mapa esquemático de carreteras del noreste de México con unidades en ruta durante la noche y los eventos que SAM revisó."
                >
                    <Terrain />

                    {(Object.keys(ROADS) as RoadId[]).map((id) => (
                        <g key={id}>
                            <path
                                d={ROADS[id].d}
                                fill="none"
                                stroke="#0e3346"
                                strokeWidth={9}
                                strokeLinecap="round"
                            />
                            <path
                                ref={(el) => {
                                    roadRefs.current[id] = el;
                                }}
                                d={ROADS[id].d}
                                fill="none"
                                stroke="#1f5a73"
                                strokeWidth={1.5}
                                strokeDasharray="6 8"
                                strokeLinecap="round"
                            />
                        </g>
                    ))}

                    {CITIES.map((c) => (
                        <g key={c.name}>
                            <circle
                                cx={c.x}
                                cy={c.y}
                                r={4}
                                fill="#061c2a"
                                stroke="#7fa3b3"
                                strokeWidth={1.5}
                            />
                            <text
                                x={c.x + c.dx}
                                y={c.y + c.dy}
                                textAnchor={c.end ? 'end' : 'start'}
                                fill="#7fa3b3"
                                fontSize={15}
                            >
                                {c.name}
                            </text>
                        </g>
                    ))}

                    {TRUCKS.map((_, i) => (
                        <g
                            key={i}
                            ref={(el) => {
                                truckRefs.current[i] = el;
                            }}
                        >
                            <circle r={9} fill="#24b6a1" opacity={0.18} />
                            <circle r={4} fill="#24b6a1" />
                        </g>
                    ))}

                    {points.length > 0 &&
                        EVENTS.map((e, i) => {
                            const point = points[i];

                            return i < passed && point ? (
                                <EventMark
                                    key={e.title + e.at}
                                    x={point.x}
                                    y={point.y}
                                    kind={e.kind}
                                    reduce={reduce}
                                />
                            ) : null;
                        })}
                </svg>

                <div className="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-white/10 px-5 py-3 text-sm text-night-muted">
                    <span>
                        <span
                            ref={clockRef}
                            className="text-lg font-semibold text-white tabular-nums"
                        >
                            {clock(reduce ? NIGHT : 0)}
                        </span>
                    </span>
                    <span>
                        <span
                            ref={reviewedRef}
                            className="font-semibold text-white tabular-nums"
                        >
                            {reduce ? TOTAL_EVENTS : 0}
                        </span>{' '}
                        eventos revisados
                    </span>
                    <span>
                        Te despertamos{' '}
                        <span
                            className={cn(
                                'font-semibold tabular-nums',
                                alerted ? 'text-night-alert' : 'text-white',
                            )}
                        >
                            {alerted} {alerted === 1 ? 'vez' : 'veces'}
                        </span>
                    </span>
                </div>

                <div className="flex items-center gap-4 border-t border-white/10 px-5 py-4">
                    <button
                        type="button"
                        onClick={() =>
                            playing
                                ? pause()
                                : play(time.get() >= NIGHT ? 0 : undefined)
                        }
                        aria-label={
                            playing
                                ? 'Pausar'
                                : time.get() >= NIGHT
                                  ? 'Repetir la noche'
                                  : 'Reproducir'
                        }
                        className="grid size-10 shrink-0 place-items-center rounded-full bg-white text-brand-night transition-transform hover:scale-105 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-aqua active:scale-95"
                    >
                        {playing ? (
                            <Pause className="size-4" fill="currentColor" />
                        ) : passed === EVENTS.length ? (
                            <RotateCcw className="size-4" strokeWidth={2.25} />
                        ) : (
                            <Play
                                className="size-4 translate-x-px"
                                fill="currentColor"
                            />
                        )}
                    </button>
                    <div className="flex-1">
                        <label htmlFor="night-range" className="sr-only">
                            Hora de la noche
                        </label>
                        <input
                            ref={rangeRef}
                            id="night-range"
                            type="range"
                            min={0}
                            max={NIGHT}
                            step={1}
                            defaultValue={reduce ? NIGHT : 0}
                            onPointerDown={pause}
                            onKeyDown={pause}
                            onInput={(e) =>
                                time.set(Number(e.currentTarget.value))
                            }
                            className="w-full cursor-pointer accent-brand-aqua"
                        />
                        <div className="mt-1 flex justify-between text-xs text-night-faint tabular-nums">
                            {[
                                '20:00',
                                '22:00',
                                '00:00',
                                '02:00',
                                '04:00',
                                '06:00',
                            ].map((h) => (
                                <span key={h}>{h}</span>
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            <ol className="flex flex-col gap-3" aria-live="polite">
                {log.length === 0 && (
                    <li className="rounded-xl border border-dashed border-white/15 p-4 text-sm text-night-faint">
                        Aún no pasa nada. Las 20:00 en punto.
                    </li>
                )}
                <AnimatePresence initial={false} mode="popLayout">
                    {log.slice(0, 5).map((e) => (
                        <motion.li
                            key={e.title + e.at}
                            layout={!reduce}
                            initial={
                                reduce
                                    ? false
                                    : { opacity: 0, y: -12, scale: 0.97 }
                            }
                            animate={{ opacity: 1, y: 0, scale: 1 }}
                            exit={{
                                opacity: 0,
                                transition: { duration: 0.15 },
                            }}
                            transition={{
                                type: 'spring',
                                stiffness: 260,
                                damping: 26,
                            }}
                            className={cn(
                                'rounded-xl border p-4',
                                e.kind === 'alert'
                                    ? 'border-night-alert/40 bg-night-alert/10'
                                    : 'border-white/10 bg-white/4',
                            )}
                        >
                            <div className="flex items-baseline justify-between gap-3">
                                <p
                                    className={cn(
                                        'text-base font-semibold',
                                        e.kind === 'alert'
                                            ? 'text-white'
                                            : 'text-night-fg',
                                    )}
                                >
                                    {e.title}
                                </p>
                                <span className="text-xs text-night-faint tabular-nums">
                                    {clock(e.at)}
                                </span>
                            </div>
                            <p
                                className={cn(
                                    'mt-1 text-sm leading-relaxed',
                                    e.kind === 'alert'
                                        ? 'text-night-alert-soft'
                                        : 'text-night-muted',
                                )}
                            >
                                {e.outcome}
                            </p>
                        </motion.li>
                    ))}
                </AnimatePresence>
            </ol>
        </div>
    );
}

function EventMark({
    x,
    y,
    kind,
    reduce,
}: {
    x: number;
    y: number;
    kind: Kind;
    reduce: boolean;
}) {
    const color =
        kind === 'alert' ? '#ff5b63' : kind === 'watch' ? '#f2b64a' : '#dbe8ee';

    return (
        <g transform={`translate(${x} ${y})`}>
            {kind === 'alert' && !reduce && (
                <motion.circle
                    r={10}
                    fill="none"
                    stroke={color}
                    strokeWidth={2}
                    initial={{ scale: 1, opacity: 0.8 }}
                    animate={{ scale: 3.2, opacity: 0 }}
                    transition={{
                        duration: 1.6,
                        repeat: Infinity,
                        ease: 'easeOut',
                    }}
                />
            )}
            <motion.circle
                r={kind === 'alert' ? 8 : 6}
                fill={color}
                initial={reduce ? false : { scale: 0, opacity: 0 }}
                animate={{
                    scale: 1,
                    // El ruido se revisa y se apaga; lo real se queda encendido.
                    opacity: kind === 'noise' ? 0.28 : 1,
                }}
                transition={{
                    scale: { type: 'spring', stiffness: 380, damping: 14 },
                    opacity: {
                        delay: kind === 'noise' ? 0.9 : 0,
                        duration: 0.8,
                    },
                }}
            />
        </g>
    );
}

/* Curvas de nivel de la Sierra Madre, solo textura. */
function Terrain() {
    return (
        <g fill="none" stroke="#0b2a3a" strokeWidth={1.2}>
            {[0, 1, 2, 3, 4, 5].map((i) => (
                <path
                    key={i}
                    d={`M-20,${470 + i * 18} C120,${420 + i * 18} 200,${520 - i * 10} 330,${460 + i * 14} S560,${600 - i * 6} 720,${520 + i * 12} S900,${470 + i * 16} 990,${500 + i * 14}`}
                />
            ))}
            {[0, 1, 2, 3].map((i) => (
                <ellipse
                    key={`e${i}`}
                    cx={120}
                    cy={250}
                    rx={70 + i * 34}
                    ry={40 + i * 22}
                    transform="rotate(-24 120 250)"
                />
            ))}
            {[0, 1, 2].map((i) => (
                <ellipse
                    key={`f${i}`}
                    cx={760}
                    cy={430}
                    rx={50 + i * 30}
                    ry={24 + i * 16}
                    transform="rotate(12 760 430)"
                />
            ))}
        </g>
    );
}
