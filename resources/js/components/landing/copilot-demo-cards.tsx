import { motion, useReducedMotion } from 'motion/react';
import { cn } from '@/lib/utils';

export type CardKind = 'night' | 'fuel' | 'compare' | 'speed';

const EASE = [0.16, 1, 0.3, 1] as const;

export function CopilotCard({ kind }: { kind: CardKind }) {
    return (
        <div className="rounded-xl border border-brand-line bg-brand-paper p-4">
            {kind === 'night' && <NightCard />}
            {kind === 'fuel' && <FuelCard />}
            {kind === 'compare' && <CompareCard />}
            {kind === 'speed' && <SpeedCard />}
        </div>
    );
}

function Grow({
    pct,
    className,
    delay = 0,
}: {
    pct: number;
    className?: string;
    delay?: number;
}) {
    const reduce = useReducedMotion();

    return (
        <motion.span
            className={cn('block h-full origin-left rounded-full', className)}
            style={{ width: `${pct}%` }}
            initial={reduce ? false : { scaleX: 0 }}
            animate={{ scaleX: 1 }}
            transition={{ duration: 0.8, delay, ease: EASE }}
        />
    );
}

function NightCard() {
    const rows = [
        {
            label: 'Botones de pánico',
            value: '2',
            note: '1 cerrado, 1 abierto en la T-600',
            alert: true,
        },
        {
            label: 'Excesos de velocidad',
            value: '14',
            note: '9 de la T-118',
        },
        {
            label: 'Eventos revisados sin molestarte',
            value: '131',
            note: 'Frenados, distracciones y paradas',
        },
    ];

    return (
        <dl className="divide-y divide-brand-line">
            {rows.map((r, i) => (
                <motion.div
                    key={r.label}
                    initial={{ opacity: 0, y: 6 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 0.1 + i * 0.08, ease: EASE }}
                    className="flex items-baseline justify-between gap-4 py-2.5 first:pt-0 last:pb-0"
                >
                    <div className="min-w-0">
                        <dt className="text-sm font-medium text-brand-ink">
                            {r.label}
                        </dt>
                        <dd className="text-xs text-brand-ink-3">{r.note}</dd>
                    </div>
                    <dd
                        className={cn(
                            'text-xl font-semibold tabular-nums',
                            r.alert ? 'text-brand-alert' : 'text-brand-ink',
                        )}
                    >
                        {r.value}
                    </dd>
                </motion.div>
            ))}
        </dl>
    );
}

const FUEL = [
    { unit: 'T-555', liters: 412 },
    { unit: 'T-600', liters: 290 },
    { unit: 'T-118', liters: 268 },
    { unit: 'T-204', liters: 251 },
    { unit: 'T-062', liters: 237 },
];
const FUEL_AVG = 298;
const FUEL_MAX = 440;

function FuelCard() {
    return (
        <div>
            <p className="text-sm font-medium text-brand-ink">
                Diésel de esta semana, en litros
            </p>
            <div className="relative mt-3 space-y-2">
                <span
                    aria-hidden="true"
                    className="absolute inset-y-0 border-l border-dashed border-brand-ink-3/60"
                    style={{
                        left: `calc(3.5rem + (100% - 7rem) * ${FUEL_AVG / FUEL_MAX})`,
                    }}
                />
                {FUEL.map((f, i) => (
                    <div
                        key={f.unit}
                        className="grid grid-cols-[3.5rem_1fr_3.5rem] items-center gap-0 text-xs"
                    >
                        <span
                            className={cn(
                                'font-medium',
                                i === 0 ? 'text-brand-ink' : 'text-brand-ink-3',
                            )}
                        >
                            {f.unit}
                        </span>
                        <span className="h-2.5">
                            <Grow
                                pct={(f.liters / FUEL_MAX) * 100}
                                delay={i * 0.06}
                                className={
                                    i === 0 ? 'bg-brand-teal' : 'bg-brand-line'
                                }
                            />
                        </span>
                        <span
                            className={cn(
                                'text-right tabular-nums',
                                i === 0
                                    ? 'font-semibold text-brand-ink'
                                    : 'text-brand-ink-3',
                            )}
                        >
                            {f.liters}
                        </span>
                    </div>
                ))}
            </div>
            <p className="mt-2 text-xs text-brand-ink-3">
                La línea punteada es el promedio de la flota: {FUEL_AVG} L.
            </p>

            <div className="mt-4 border-t border-brand-line pt-3">
                <p className="text-sm font-medium text-brand-ink">
                    T-555 el martes
                </p>
                <div className="mt-2 flex h-3 overflow-hidden rounded-full bg-brand-line/60">
                    <Segment grow={4} className="bg-brand-line" />
                    <Segment grow={9} className="bg-brand-petrol" delay={0.2} />
                    <Segment grow={3} className="bg-brand-aqua" delay={0.3} />
                    <Segment grow={8} className="bg-brand-line" />
                </div>
                <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-brand-ink-3">
                    <Legend className="bg-brand-petrol">
                        9 h encendida sin moverse, patio Tultitlán
                    </Legend>
                    <Legend className="bg-brand-aqua">3 h en ruta</Legend>
                </div>
            </div>
        </div>
    );
}

function Segment({
    grow,
    className,
    delay = 0,
}: {
    grow: number;
    className: string;
    delay?: number;
}) {
    const reduce = useReducedMotion();

    return (
        <motion.span
            className={cn('h-full', className)}
            style={{ flexGrow: grow }}
            initial={reduce ? false : { opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ duration: 0.6, delay }}
        />
    );
}

function Legend({
    className,
    children,
}: {
    className: string;
    children: string;
}) {
    return (
        <span className="flex items-center gap-1.5">
            <span className={cn('size-2 rounded-full', className)} />
            {children}
        </span>
    );
}

function CompareCard() {
    const metrics = [
        { label: 'Diésel', a: 412, b: 290, unit: 'L', max: 440 },
        {
            label: 'Horas encendida sin moverse',
            a: 9,
            b: 1,
            unit: 'h',
            max: 10,
        },
    ];

    return (
        <div className="space-y-4">
            {metrics.map((m) => (
                <div key={m.label}>
                    <p className="text-sm font-medium text-brand-ink">
                        {m.label}
                    </p>
                    <div className="mt-2 space-y-1.5 text-xs">
                        {[
                            { unit: 'T-555', v: m.a, cls: 'bg-brand-petrol' },
                            { unit: 'T-600', v: m.b, cls: 'bg-brand-aqua' },
                        ].map((row, i) => (
                            <div
                                key={row.unit}
                                className="grid grid-cols-[3.5rem_1fr_3rem] items-center"
                            >
                                <span className="text-brand-ink-3">
                                    {row.unit}
                                </span>
                                <span className="h-2.5">
                                    <Grow
                                        pct={(row.v / m.max) * 100}
                                        delay={i * 0.1}
                                        className={row.cls}
                                    />
                                </span>
                                <span className="text-right font-medium text-brand-ink tabular-nums">
                                    {row.v} {m.unit}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
}

/* Velocidad de la T-118 entre 22:30 y 01:30 contra el límite de 80 km/h. */
const SPEED = [
    62, 70, 78, 86, 92, 84, 76, 88, 97, 104, 95, 82, 74, 79, 90, 94, 85, 77, 72,
    68,
];

function SpeedCard() {
    const reduce = useReducedMotion();
    const w = 320;
    const h = 110;
    const min = 50;
    const max = 110;
    const x = (i: number) => (i / (SPEED.length - 1)) * w;
    const y = (v: number) => h - ((v - min) / (max - min)) * h;
    const line = SPEED.map(
        (v, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(v).toFixed(1)}`,
    ).join(' ');
    const peak = SPEED.indexOf(Math.max(...SPEED));

    return (
        <div>
            <p className="text-sm font-medium text-brand-ink">
                T-118, Carr. 57, de 22:30 a 01:30
            </p>
            <svg
                viewBox={`0 0 ${w} ${h + 18}`}
                className="mt-3 w-full"
                role="img"
                aria-label="Velocidad de la T-118: varios tramos arriba del límite de 80 km/h, con un máximo de 104."
            >
                <rect
                    x={0}
                    y={0}
                    width={w}
                    height={y(80)}
                    className="fill-brand-alert/6"
                />
                <line
                    x1={0}
                    x2={w}
                    y1={y(80)}
                    y2={y(80)}
                    className="stroke-brand-alert/60"
                    strokeDasharray="4 4"
                />
                <text
                    x={w}
                    y={y(80) - 5}
                    textAnchor="end"
                    className="fill-brand-alert text-3xs"
                >
                    Límite 80 km/h
                </text>
                <motion.path
                    d={line}
                    fill="none"
                    className="stroke-brand-petrol"
                    strokeWidth={2}
                    strokeLinejoin="round"
                    initial={reduce ? false : { pathLength: 0 }}
                    animate={{ pathLength: 1 }}
                    transition={{ duration: 1.2, ease: EASE }}
                />
                <motion.g
                    initial={reduce ? false : { opacity: 0, scale: 0.6 }}
                    animate={{ opacity: 1, scale: 1 }}
                    transition={{ delay: 1, type: 'spring', stiffness: 260 }}
                    style={{ transformOrigin: `${x(peak)}px ${y(104)}px` }}
                >
                    <circle
                        cx={x(peak)}
                        cy={y(104)}
                        r={4.5}
                        className="fill-brand-alert"
                    />
                    <text
                        x={x(peak) + 8}
                        y={y(104) + 4}
                        className="fill-brand-ink text-2xs font-semibold"
                    >
                        104 km/h
                    </text>
                </motion.g>
                <text x={0} y={h + 15} className="fill-brand-ink-3 text-3xs">
                    22:30
                </text>
                <text
                    x={w}
                    y={h + 15}
                    textAnchor="end"
                    className="fill-brand-ink-3 text-3xs"
                >
                    01:30
                </text>
            </svg>
        </div>
    );
}
