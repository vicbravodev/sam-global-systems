import {
    Camera,
    MapPin,
    MessageSquare,
    PhoneCall,
    Smartphone,
    UserRound,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { ConfidenceBar } from '@/components/sam/confidence-bar';
import { SeverityBadge } from '@/components/sam/severity-badge';
import { StatusPill } from '@/components/sam/status-pill';
import { cn } from '@/lib/utils';
import { Reveal } from './reveal';

/**
 * Cinco capacidades, cinco celdas (6 columnas: 4+2 arriba, 2+2+2 abajo).
 * Cada celda lleva una pieza real del DS en vez de un icono decorativo.
 */
export function FeatureBento() {
    return (
        <div className="grid gap-4 md:grid-cols-6">
            <Cell
                className="md:col-span-4"
                tone="primary"
                title="Investiga cada alerta por ti"
                body="Cruza ubicación, cámaras e historial del conductor antes de decidir si vale la pena molestarte."
            >
                <EvidenceCard />
            </Cell>

            <Cell
                delay={80}
                className="md:col-span-2"
                title="Avisa por el canal correcto"
                body="Llamada para emergencias, WhatsApp para lo importante y SMS de respaldo."
            >
                <Channels />
            </Cell>

            <Cell
                delay={60}
                className="md:col-span-2"
                title="Bandeja por prioridad"
                body="Cada caso llega clasificado por severidad y estado."
            >
                <div className="flex flex-col gap-2">
                    <QueueLine title="Botón de pánico">
                        <SeverityBadge level="critical" />
                        <StatusPill state="escalated" />
                    </QueueLine>
                    <QueueLine title="Unidad sin señal">
                        <SeverityBadge level="medium" />
                        <StatusPill state="in-progress" />
                    </QueueLine>
                    <QueueLine title="Frenado brusco" muted>
                        <SeverityBadge level="low" />
                        <StatusPill state="discarded" />
                    </QueueLine>
                </div>
            </Cell>

            <Cell
                delay={120}
                className="md:col-span-2"
                title="Pregunta como le preguntarías a un monitorista"
                body="Sin filtros ni reportes: escribe y SAM responde con datos de tu flota."
            >
                <div className="flex flex-col gap-2 text-sm">
                    <p className="self-end rounded-lg rounded-br-sm bg-primary px-3 py-2 text-primary-foreground">
                        ¿Dónde está la T-204?
                    </p>
                    <p className="max-w-[92%] rounded-lg rounded-bl-sm border border-border bg-surface-2 px-3 py-2 leading-relaxed text-fg-2">
                        En la Carr. 57 km 112, rumbo a San Luis Potosí. Sin
                        alertas abiertas.
                    </p>
                </div>
            </Cell>

            <Cell
                delay={180}
                className="md:col-span-2"
                tone="grid"
                title="No suelta lo que no está claro"
                body="Si la evidencia no alcanza, el caso sigue abierto y vigilado hasta resolverse."
            >
                <div className="flex items-center gap-3">
                    <span className="relative flex size-3">
                        <span className="absolute inset-0 animate-ping rounded-full bg-status-in-progress opacity-50 motion-reduce:hidden" />
                        <span className="relative size-3 rounded-full bg-status-in-progress" />
                    </span>
                    <span className="font-mono text-xs text-fg-2">
                        T-087 · vigilando
                    </span>
                </div>
            </Cell>
        </div>
    );
}

function Cell({
    title,
    body,
    children,
    className,
    tone,
    delay = 0,
}: {
    title: string;
    body: string;
    children: ReactNode;
    className?: string;
    tone?: 'primary' | 'grid';
    delay?: number;
}) {
    return (
        <Reveal delay={delay} className={className}>
            <article
                className={cn(
                    'relative flex h-full flex-col justify-between gap-8 overflow-hidden rounded-xl border border-border bg-surface-1 p-6 sm:p-7',
                    tone === 'primary' &&
                        'bg-[linear-gradient(135deg,color-mix(in_oklch,var(--primary)_14%,var(--surface-1)),var(--surface-1)_65%)]',
                )}
            >
                {tone === 'grid' && (
                    <div
                        aria-hidden="true"
                        className="pointer-events-none absolute inset-0 [background-image:radial-gradient(var(--border-strong)_1px,transparent_1px)] [mask-image:linear-gradient(to_bottom,transparent,black)] [background-size:14px_14px] opacity-40"
                    />
                )}
                <div className="relative">
                    <h3 className="text-lg font-semibold tracking-tight text-balance text-fg-1">
                        {title}
                    </h3>
                    <p className="mt-2 max-w-md text-base leading-relaxed text-fg-3">
                        {body}
                    </p>
                </div>
                <div className="relative">{children}</div>
            </article>
        </Reveal>
    );
}

function EvidenceCard() {
    return (
        <div className="rounded-lg border border-border bg-surface-1/80 shadow-md backdrop-blur-sm">
            <div className="grid divide-y divide-border sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                <Evidence
                    icon={MapPin}
                    label="Ubicación"
                    value="Carr. 40D km 18, detenida"
                />
                <Evidence
                    icon={Camera}
                    label="Cámaras"
                    value="Frontal y cabina revisadas"
                />
                <Evidence
                    icon={UserRound}
                    label="Conductor"
                    value="Sin eventos en 90 días"
                />
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                <span className="text-xs font-medium text-fg-2">
                    Veredicto: emergencia real
                </span>
                <ConfidenceBar value={0.92} />
            </div>
        </div>
    );
}

function Evidence({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof MapPin;
    label: string;
    value: string;
}) {
    return (
        <div className="px-4 py-3.5">
            <span className="flex items-center gap-1.5 text-2xs font-medium tracking-label text-fg-3 uppercase">
                <Icon className="size-3.5" strokeWidth={1.75} />
                {label}
            </span>
            <p className="mt-1.5 text-sm text-fg-1">{value}</p>
        </div>
    );
}

const CHANNELS = [
    { icon: PhoneCall, label: 'Llamada', state: 'Contestada', ok: true },
    { icon: MessageSquare, label: 'WhatsApp', state: 'Leído', ok: true },
    { icon: Smartphone, label: 'SMS', state: 'Respaldo', ok: false },
];

function Channels() {
    return (
        <ul className="flex flex-col gap-2">
            {CHANNELS.map((c) => (
                <li
                    key={c.label}
                    className="flex items-center justify-between rounded-md border border-border bg-surface-2 px-3 py-2"
                >
                    <span className="flex items-center gap-2 text-sm text-fg-1">
                        <c.icon
                            className="size-4 text-fg-3"
                            strokeWidth={1.75}
                        />
                        {c.label}
                    </span>
                    <span
                        className={cn(
                            'font-mono text-2xs',
                            c.ok ? 'text-health-ok' : 'text-fg-3',
                        )}
                    >
                        {c.state}
                    </span>
                </li>
            ))}
        </ul>
    );
}

function QueueLine({
    title,
    muted,
    children,
}: {
    title: string;
    muted?: boolean;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'flex items-center justify-between gap-2 rounded-md border border-border bg-surface-2 px-3 py-2',
                muted && 'opacity-60',
            )}
        >
            <span className="truncate text-sm text-fg-1">{title}</span>
            <span className="flex shrink-0 gap-1">{children}</span>
        </div>
    );
}
