import { Check, PhoneCall } from 'lucide-react';
import {
    AnimatePresence,
    motion,
    useInView,
    useReducedMotion,
} from 'motion/react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

type Frame = {
    at: string;
    camera: 'Frontal' | 'Cabina';
    speed: number;
    /** Qué dibujar dentro del cuadro. */
    scene: 'road' | 'shoulder' | 'cabin';
};

/* Las seis fotos que SAM pide alrededor del pánico de la T-214. */
const FRAMES: Frame[] = [
    { at: '-15 s', camera: 'Frontal', speed: 64, scene: 'road' },
    { at: '-10 s', camera: 'Frontal', speed: 31, scene: 'road' },
    { at: '-5 s', camera: 'Frontal', speed: 0, scene: 'shoulder' },
    { at: '0 s', camera: 'Cabina', speed: 0, scene: 'cabin' },
    { at: '+5 s', camera: 'Cabina', speed: 0, scene: 'cabin' },
    { at: '+10 s', camera: 'Frontal', speed: 0, scene: 'shoulder' },
];

const FINDINGS = [
    'Un solo operador en la cabina, sin pasajeros',
    'Sin personas ni amenazas visibles alrededor',
    'Cabina en orden, el operador tranquilo',
    'Unidad detenida en el acotamiento, con intermitentes',
];

const EASE = [0.16, 1, 0.3, 1] as const;

/**
 * Qué ve la IA en las cámaras. Los cuadros son ilustraciones esquemáticas
 * (no fotos reales); al entrar en pantalla SAM "analiza" cada foto y marca lo
 * que encontró. Se puede elegir cualquier foto.
 */
export function CabinVision() {
    const reduce = useReducedMotion() ?? false;
    const ref = useRef<HTMLDivElement>(null);
    const inView = useInView(ref, { once: true, amount: 0.35 });
    const [active, setActive] = useState(reduce ? 3 : 0);
    const [scanned, setScanned] = useState(reduce ? FRAMES.length : 0);
    const [touched, setTouched] = useState(false);

    // Recorre las seis fotos una vez; se detiene si el visitante elige una.
    useEffect(() => {
        if (!inView || reduce || touched || scanned >= FRAMES.length) {
            return;
        }

        const id = window.setTimeout(() => {
            setScanned((s) => s + 1);
            setActive((a) => Math.min(a + 1, FRAMES.length - 1));
        }, 1100);

        return () => window.clearTimeout(id);
    }, [inView, reduce, touched, scanned]);

    const done = scanned >= FRAMES.length || touched;
    const findings = done
        ? FINDINGS.length
        : Math.min(FINDINGS.length, Math.max(0, scanned - 2));

    return (
        <div
            ref={ref}
            className="grid gap-10 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)] lg:gap-14"
        >
            <div>
                <div className="relative overflow-hidden rounded-[1.25rem] bg-brand-night">
                    <AnimatePresence mode="wait" initial={false}>
                        <motion.div
                            key={active}
                            initial={reduce ? false : { opacity: 0.4 }}
                            animate={{ opacity: 1 }}
                            transition={{ duration: 0.35 }}
                        >
                            <FrameArt
                                frame={FRAMES[active]}
                                showBoxes={done || active < scanned}
                                reduce={reduce}
                            />
                        </motion.div>
                    </AnimatePresence>
                    {!done && !reduce && inView && (
                        <motion.span
                            aria-hidden="true"
                            className="pointer-events-none absolute inset-x-0 h-16 bg-linear-to-b from-transparent via-brand-aqua/25 to-transparent"
                            initial={{ top: '-15%' }}
                            animate={{ top: '100%' }}
                            transition={{
                                duration: 1.1,
                                repeat: Infinity,
                                ease: 'linear',
                            }}
                        />
                    )}
                </div>

                <div
                    className="mt-3 grid grid-cols-6 gap-2"
                    role="listbox"
                    aria-label="Fotos alrededor del evento"
                >
                    {FRAMES.map((f, i) => (
                        <button
                            key={f.at}
                            type="button"
                            role="option"
                            aria-selected={active === i}
                            onClick={() => {
                                setTouched(true);
                                setActive(i);
                            }}
                            className={cn(
                                'group overflow-hidden rounded-lg border-2 text-left transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-teal',
                                active === i
                                    ? 'border-brand-teal'
                                    : 'border-transparent hover:border-brand-line',
                            )}
                        >
                            <span className="block bg-brand-night">
                                <FrameArt frame={f} thumb reduce />
                            </span>
                            <span className="block bg-white px-1.5 py-1 text-xs text-brand-ink-3 tabular-nums">
                                {f.at}
                                <span className="float-right">
                                    {i < scanned || done ? (
                                        <Check
                                            className="inline size-3 text-brand-aqua"
                                            strokeWidth={3}
                                        />
                                    ) : null}
                                </span>
                            </span>
                        </button>
                    ))}
                </div>
            </div>

            <div className="flex flex-col">
                <p className="text-base text-brand-ink-3">
                    Botón de pánico, T-214, 23:48
                </p>
                <ul className="mt-5 space-y-3">
                    {FINDINGS.map((text, i) => (
                        <li
                            key={text}
                            className={cn(
                                'flex gap-3 text-lg leading-snug transition-[opacity,color] duration-500',
                                i < findings
                                    ? 'text-brand-ink'
                                    : 'text-brand-ink-3/40',
                            )}
                        >
                            <span
                                className={cn(
                                    'mt-1 grid size-5 shrink-0 place-items-center rounded-full transition-colors duration-500',
                                    i < findings
                                        ? 'bg-brand-aqua text-white'
                                        : 'bg-brand-line text-transparent',
                                )}
                            >
                                <Check className="size-3" strokeWidth={3} />
                            </span>
                            {text}
                        </li>
                    ))}
                </ul>

                <AnimatePresence>
                    {done && (
                        <motion.div
                            initial={reduce ? false : { opacity: 0, y: 12 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.6, ease: EASE }}
                            className="mt-8 rounded-2xl border border-brand-line bg-white p-5"
                        >
                            <p className="text-base leading-relaxed text-brand-ink-2">
                                &ldquo;El operador está solo y tranquilo; no se
                                ven amenazas. Probable activación por error. Se
                                confirma con una llamada.&rdquo;
                            </p>
                            <p className="mt-4 flex items-center gap-2 border-t border-brand-line pt-4 text-base text-brand-ink">
                                <PhoneCall
                                    className="size-4 text-brand-teal"
                                    strokeWidth={2}
                                />
                                El operador contestó y marcó 2: falsa alarma.
                                Caso cerrado.
                            </p>
                        </motion.div>
                    )}
                </AnimatePresence>
            </div>
        </div>
    );
}

/* ---------- Ilustración de cada cuadro (line art, no es una foto) ---------- */

const LINE = '#2a6278';
const SOFT = '#123447';
const AQUA = '#24b6a1';

function FrameArt({
    frame,
    thumb = false,
    showBoxes = false,
    reduce,
}: {
    frame: Frame;
    thumb?: boolean;
    showBoxes?: boolean;
    reduce: boolean;
}) {
    return (
        <svg
            viewBox="0 0 320 180"
            className="block h-auto w-full"
            role={thumb ? undefined : 'img'}
            aria-hidden={thumb ? true : undefined}
            aria-label={
                thumb
                    ? undefined
                    : `Cámara ${frame.camera.toLowerCase()}, ${frame.at} del evento`
            }
        >
            <rect width={320} height={180} fill="#071b27" />
            {frame.scene === 'cabin' ? (
                <Cabin />
            ) : (
                <Road shoulder={frame.scene === 'shoulder'} />
            )}

            {showBoxes && !thumb && frame.scene === 'cabin' && (
                <>
                    <DetectBox
                        x={62}
                        y={40}
                        w={82}
                        h={112}
                        label="Operador"
                        reduce={reduce}
                    />
                    <DetectBox
                        x={188}
                        y={58}
                        w={78}
                        h={94}
                        label="Asiento vacío"
                        reduce={reduce}
                        delay={0.15}
                    />
                </>
            )}
            {showBoxes && !thumb && frame.scene === 'shoulder' && (
                <DetectBox
                    x={112}
                    y={96}
                    w={96}
                    h={40}
                    label="Camino despejado"
                    reduce={reduce}
                />
            )}

            {!thumb && (
                <text
                    x={12}
                    y={170}
                    fill="#8fb0bf"
                    fontSize={7}
                    style={{ fontVariantNumeric: 'tabular-nums' }}
                >
                    {`T-214  ${frame.camera}  ${frame.speed} km/h  ${frame.at}`}
                </text>
            )}
        </svg>
    );
}

function Road({ shoulder }: { shoulder: boolean }) {
    return (
        <g>
            <path d="M0,74 L320,74" stroke={SOFT} />
            <path
                d="M0,74 C40,62 70,66 100,58 C130,50 160,62 200,54 C240,46 280,60 320,52 L320,74 L0,74 Z"
                fill="#0b2533"
            />
            <path d="M40,180 L150,74 L170,74 L280,180 Z" fill="#0d2a39" />
            <path d="M40,180 L150,74" stroke={LINE} strokeWidth={1.5} />
            <path d="M280,180 L170,74" stroke={LINE} strokeWidth={1.5} />
            {[0, 1, 2, 3].map((i) => (
                <path
                    key={i}
                    d={`M${160 - i * 1},${84 + i * 24} L${160 - i * 1.5},${96 + i * 26}`}
                    stroke="#4c8aa0"
                    strokeWidth={1 + i * 0.8}
                />
            ))}
            {shoulder ? (
                <g>
                    <path
                        d="M150,74 L60,180"
                        stroke="#e0b24a"
                        strokeWidth={1}
                        strokeDasharray="3 3"
                    />
                    <circle
                        cx={30}
                        cy={150}
                        r={5}
                        fill="#e0b24a"
                        opacity={0.7}
                    />
                    <circle
                        cx={290}
                        cy={150}
                        r={5}
                        fill="#e0b24a"
                        opacity={0.7}
                    />
                </g>
            ) : (
                <g>
                    <rect
                        x={170}
                        y={88}
                        width={22}
                        height={14}
                        rx={2}
                        fill="none"
                        stroke={LINE}
                    />
                    <circle cx={174} cy={100} r={1.5} fill="#ff5b63" />
                    <circle cx={188} cy={100} r={1.5} fill="#ff5b63" />
                </g>
            )}
        </g>
    );
}

function Cabin() {
    return (
        <g fill="none" stroke={LINE} strokeWidth={1.5}>
            <path
                d="M20,20 C100,8 220,8 300,20 L286,60 C200,52 120,52 34,60 Z"
                fill="#0b2533"
            />
            <path d="M0,150 C80,132 240,132 320,150" />
            {/* Operador */}
            <circle cx={103} cy={72} r={16} fill="#0d2a39" />
            <path
                d="M70,150 C72,112 86,96 103,96 C120,96 134,112 136,150"
                fill="#0d2a39"
            />
            {/* Volante */}
            <ellipse cx={103} cy={132} rx={34} ry={12} />
            {/* Asiento del copiloto, vacío */}
            <path
                d="M200,150 L204,74 C206,64 248,64 250,74 L256,150"
                fill="#0b2533"
            />
            <path d="M212,112 L244,112" stroke={SOFT} />
        </g>
    );
}

function DetectBox({
    x,
    y,
    w,
    h,
    label,
    reduce,
    delay = 0,
}: {
    x: number;
    y: number;
    w: number;
    h: number;
    label: string;
    reduce: boolean;
    delay?: number;
}) {
    return (
        <motion.g
            initial={reduce ? false : { opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ delay, duration: 0.4 }}
        >
            <rect
                x={x}
                y={y}
                width={w}
                height={h}
                rx={3}
                fill="none"
                stroke={AQUA}
                strokeWidth={1.5}
            />
            <rect
                x={x}
                y={y - 13}
                width={label.length * 5.2 + 10}
                height={13}
                rx={2}
                fill={AQUA}
            />
            <text
                x={x + 5}
                y={y - 3.5}
                fill="#04141f"
                fontSize={8.5}
                fontWeight={600}
            >
                {label}
            </text>
        </motion.g>
    );
}
