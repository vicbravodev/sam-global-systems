import { Inbox, Sparkles } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { Severity } from '@/components/sam/severity-badge';
import { SeverityBadge } from '@/components/sam/severity-badge';
import type { IncidentStatus } from '@/components/sam/status-pill';
import { StatusPill } from '@/components/sam/status-pill';
import { cn } from '@/lib/utils';

type ScriptedEvent = {
    title: string;
    unit: string;
    place: string;
    severity: Severity;
    verdict: IncidentStatus;
    note: string;
};

/* Guion ilustrativo: la mezcla real de una flota (mucho ruido, pocas
   emergencias). Las emergencias abren incidente sin esperar evaluación, igual
   que el pipeline. */
const SCRIPT: ScriptedEvent[] = [
    {
        title: 'Frenado brusco',
        unit: 'T-118',
        place: 'Av. Constitución, Monterrey',
        severity: 'low',
        verdict: 'discarded',
        note: 'Tráfico detenido adelante. Sin impacto.',
    },
    {
        title: 'Botón de pánico',
        unit: 'T-031',
        place: 'Carr. 40D km 18, Saltillo',
        severity: 'critical',
        verdict: 'escalated',
        note: 'Ubicación y video confirmados. Llamando al operador.',
    },
    {
        title: 'Exceso de velocidad',
        unit: 'T-204',
        place: 'Carr. 57 km 112',
        severity: 'low',
        verdict: 'resolved',
        note: 'Registrado en el historial del conductor.',
    },
    {
        title: 'Unidad sin señal',
        unit: 'T-087',
        place: 'Carr. 85, Sabinas Hidalgo',
        severity: 'medium',
        verdict: 'in-progress',
        note: 'Estaba en ruta. Vigilando hasta que reconecte.',
    },
    {
        title: 'Distracción al volante',
        unit: 'T-062',
        place: 'Periférico, San Nicolás',
        severity: 'low',
        verdict: 'discarded',
        note: 'Evento aislado. Documentado.',
    },
    {
        title: 'Posible colisión',
        unit: 'T-150',
        place: 'Libramiento Noreste, Escobedo',
        severity: 'high',
        verdict: 'escalated',
        note: 'Incidente abierto al instante. Revisando video.',
    },
    {
        title: 'Parada no programada',
        unit: 'T-175',
        place: 'Carr. 54, Ciénega de Flores',
        severity: 'info',
        verdict: 'resolved',
        note: 'Carga de combustible en estación autorizada.',
    },
];

type Row = { id: number; event: ScriptedEvent; investigating: boolean };

const MAX_ROWS = 5;
const TICK_MS = 2800;
const INVESTIGATE_MS = 1500;
const ESCALATING: IncidentStatus[] = ['escalated'];

function seedRows(): Row[] {
    return [4, 3, 2, 1, 0].map((i, n) => ({
        id: -n - 1,
        event: SCRIPT[i],
        investigating: false,
    }));
}

/**
 * Consola del hero: una bandeja que recibe eventos y los resuelve en vivo,
 * construida con los chips reales del DS (SeverityBadge, StatusPill). Se
 * pausa fuera de viewport y con la pestaña oculta; con reduced-motion queda
 * como una foto fija.
 */
export function LiveTriage({ className }: { className?: string }) {
    const [rows, setRows] = useState<Row[]>(seedRows);
    const [received, setReceived] = useState(412);
    const [escalated, setEscalated] = useState(6);
    const rootRef = useRef<HTMLDivElement>(null);
    const cursor = useRef(5);
    const nextId = useRef(1);

    useEffect(() => {
        const node = rootRef.current;

        if (
            !node ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            return;
        }

        let inView = true;
        const timeouts = new Set<number>();

        const observer = new IntersectionObserver(([entry]) => {
            inView = entry.isIntersecting;
        });
        observer.observe(node);

        const tick = () => {
            if (!inView || document.hidden) {
                return;
            }

            const event = SCRIPT[cursor.current % SCRIPT.length];
            const id = nextId.current++;
            cursor.current += 1;

            setRows((prev) =>
                [{ id, event, investigating: true }, ...prev].slice(
                    0,
                    MAX_ROWS,
                ),
            );
            setReceived((n) => n + 1);

            const t = window.setTimeout(() => {
                timeouts.delete(t);
                setRows((prev) =>
                    prev.map((r) =>
                        r.id === id ? { ...r, investigating: false } : r,
                    ),
                );

                if (ESCALATING.includes(event.verdict)) {
                    setEscalated((n) => n + 1);
                }
            }, INVESTIGATE_MS);
            timeouts.add(t);
        };

        const interval = window.setInterval(tick, TICK_MS);

        return () => {
            window.clearInterval(interval);
            timeouts.forEach((t) => window.clearTimeout(t));
            observer.disconnect();
        };
    }, []);

    return (
        <div
            ref={rootRef}
            className={cn(
                'overflow-hidden rounded-xl border border-border bg-surface-1 shadow-xl',
                className,
            )}
        >
            <div className="flex items-center justify-between border-b border-border px-4 py-3">
                <div className="flex items-center gap-2 text-2xs font-medium tracking-label text-fg-3 uppercase">
                    <Inbox className="size-3.5" strokeWidth={1.75} />
                    Bandeja de incidentes
                </div>
                <span className="flex items-center gap-1.5 text-2xs text-fg-3">
                    <span className="relative flex size-1.5">
                        <span className="absolute inset-0 animate-ping rounded-full bg-health-ok opacity-60 motion-reduce:hidden" />
                        <span className="relative size-1.5 rounded-full bg-health-ok" />
                    </span>
                    En vivo
                </span>
            </div>

            <ol className="h-[23.5rem] divide-y divide-border overflow-hidden">
                {rows.map((row, index) => (
                    <TriageRow key={row.id} row={row} fresh={index === 0} />
                ))}
            </ol>

            <div className="flex items-center justify-between border-t border-border bg-surface-2/60 px-4 py-2.5 font-mono text-2xs text-fg-3 tabular-nums">
                <span>
                    Hoy{' '}
                    <span className="text-fg-1">
                        {received.toLocaleString('es-MX')}
                    </span>{' '}
                    eventos recibidos
                </span>
                <span>
                    <span className="text-severity-critical">{escalated}</span>{' '}
                    llegaron a tu equipo
                </span>
            </div>
        </div>
    );
}

function TriageRow({ row, fresh }: { row: Row; fresh: boolean }) {
    const { event, investigating } = row;
    const urgent = !investigating && event.verdict === 'escalated';
    const quiet =
        !investigating &&
        (event.verdict === 'discarded' || event.verdict === 'resolved');

    return (
        <li
            className={cn(
                'sam-row-in relative px-4 py-3.5 transition-colors duration-500',
                urgent && 'bg-severity-critical-bg/35',
                fresh && investigating && 'bg-surface-2/70',
            )}
        >
            {urgent && (
                <span
                    aria-hidden="true"
                    className="absolute inset-y-0 left-0 w-0.5 bg-severity-critical"
                />
            )}
            <div className="flex items-center justify-between gap-3">
                <div
                    className={cn(
                        'min-w-0 transition-opacity duration-500',
                        quiet && 'opacity-70',
                    )}
                >
                    <p className="truncate text-sm font-semibold text-fg-1">
                        {event.title}
                        <span className="ml-2 font-mono text-2xs font-normal text-fg-3">
                            {event.unit}
                        </span>
                    </p>
                    <p className="mt-0.5 truncate text-xs text-fg-3">
                        {investigating ? event.place : event.note}
                    </p>
                </div>
                <div className="flex shrink-0 items-center gap-1.5">
                    <SeverityBadge level={event.severity} />
                    {investigating ? (
                        <span className="inline-flex items-center gap-1.5 rounded-sm border border-ai-accent/40 bg-ai-accent/12 px-1.5 py-1 text-3xs font-semibold tracking-label text-ai-accent">
                            <Sparkles
                                className="size-3 animate-pulse"
                                strokeWidth={1.75}
                                aria-hidden="true"
                            />
                            Investigando
                        </span>
                    ) : (
                        <StatusPill state={event.verdict} />
                    )}
                </div>
            </div>
        </li>
    );
}
