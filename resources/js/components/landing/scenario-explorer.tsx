import { useState } from 'react';
import type { Severity } from '@/components/sam/severity-badge';
import { SeverityBadge } from '@/components/sam/severity-badge';
import type { IncidentStatus } from '@/components/sam/status-pill';
import { StatusPill } from '@/components/sam/status-pill';
import { TabBar } from '@/components/sam/tab-bar';
import { cn } from '@/lib/utils';

type Scenario = {
    key: string;
    label: string;
    title: string;
    severity: Severity;
    status: IncidentStatus;
    outcome: string;
    steps: { at: string; text: string; strong?: boolean }[];
};

/* Tiempos ilustrativos. El orden refleja el pipeline real: las emergencias
   abren incidente antes de cualquier evaluación. */
const SCENARIOS: Scenario[] = [
    {
        key: 'panico',
        label: 'Botón de pánico',
        title: 'El operador de la T-031 activa el botón de pánico',
        severity: 'critical',
        status: 'escalated',
        outcome: 'Tu jefe de turno recibe el caso con la evidencia ya armada.',
        steps: [
            { at: '0:00', text: 'Samsara reporta el pánico en la unidad.' },
            {
                at: '0:02',
                text: 'Se abre el incidente de inmediato, sin esperar evaluación.',
                strong: true,
            },
            {
                at: '0:06',
                text: 'Ubicación confirmada: Carr. 40D km 18, unidad detenida.',
            },
            { at: '0:15', text: 'Llamada al operador. No contesta.' },
            {
                at: '0:40',
                text: 'Escalado a tu equipo por llamada y WhatsApp.',
                strong: true,
            },
        ],
    },
    {
        key: 'frenado',
        label: 'Frenado brusco',
        title: 'La T-118 frena de golpe en Av. Constitución',
        severity: 'low',
        status: 'discarded',
        outcome: 'Nadie de tu equipo fue interrumpido. Queda el registro.',
        steps: [
            { at: '0:00', text: 'Samsara reporta un frenado brusco.' },
            {
                at: '0:08',
                text: 'SAM revisa la velocidad previa y el video frontal.',
            },
            {
                at: '0:21',
                text: 'Tráfico detenido adelante. Sin impacto ni conducta de riesgo.',
            },
            {
                at: '0:23',
                text: 'Descartado y documentado en el historial del conductor.',
                strong: true,
            },
        ],
    },
    {
        key: 'senal',
        label: 'Unidad sin señal',
        title: 'El equipo de la T-087 deja de reportar',
        severity: 'medium',
        status: 'in-progress',
        outcome: 'Vigilado hasta que la unidad reconecte o se escale.',
        steps: [
            { at: '0:00', text: 'El gateway de la unidad deja de reportar.' },
            {
                at: '0:01',
                text: 'Última posición: en ruta sobre la Carr. 85.',
            },
            {
                at: '0:02',
                text: 'Espera el umbral antes de alertar, para no avisar por una zona sin cobertura.',
            },
            {
                at: 'luego',
                text: 'Mantiene el caso abierto y avisa a tu equipo si no reconecta.',
                strong: true,
            },
        ],
    },
];

export function ScenarioExplorer() {
    const [active, setActive] = useState(SCENARIOS[0].key);
    const scenario = SCENARIOS.find((s) => s.key === active) ?? SCENARIOS[0];

    return (
        <div>
            <TabBar
                aria-label="Escenarios"
                items={SCENARIOS.map((s) => ({ key: s.key, label: s.label }))}
                value={active}
                onChange={setActive}
                className="scrollbar-none overflow-x-auto"
            />

            <div
                key={scenario.key}
                role="tabpanel"
                className="grid gap-10 pt-10 lg:grid-cols-[0.9fr_1.1fr] lg:gap-16"
            >
                <div className="sam-row-in">
                    <div className="flex items-center gap-2">
                        <SeverityBadge level={scenario.severity} />
                        <StatusPill state={scenario.status} />
                    </div>
                    <h3 className="mt-5 text-xl font-semibold tracking-tight text-balance text-fg-1">
                        {scenario.title}
                    </h3>
                    <p className="mt-4 max-w-sm text-md leading-relaxed text-fg-2">
                        {scenario.outcome}
                    </p>
                </div>

                <ol className="relative">
                    {scenario.steps.map((step, i) => (
                        <li
                            key={step.text}
                            style={{ animationDelay: `${120 + i * 110}ms` }}
                            className="group sam-row-in grid grid-cols-[3.5rem_1fr] gap-4"
                        >
                            <span className="pt-0.5 text-right font-mono text-xs text-fg-3 tabular-nums">
                                {step.at === 'luego' ? 'luego' : `+${step.at}`}
                            </span>
                            <p
                                className={cn(
                                    'relative border-l border-border pb-6 pl-5 text-base leading-relaxed group-last:border-transparent group-last:pb-0',
                                    step.strong
                                        ? 'font-medium text-fg-1'
                                        : 'text-fg-2',
                                )}
                            >
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'absolute top-2 left-0 size-1.5 -translate-x-1/2 rounded-full',
                                        step.strong
                                            ? 'bg-primary'
                                            : 'bg-border-strong',
                                    )}
                                />
                                {step.text}
                            </p>
                        </li>
                    ))}
                </ol>
            </div>
        </div>
    );
}
