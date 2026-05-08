import {
    BarChart2,
    ChevronDown,
    ChevronRight,
    FileCode,
    Map,
    Video,
} from 'lucide-react';
import { useState } from 'react';
import { ConfidenceBar } from '@/components/sam';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { AiDecision, IncidentDetail } from '@/types/sam';

// ---- AI Decision label ----

const DECISION_LABEL: Record<AiDecision, string> = {
    incident: 'Incidente confirmado',
    escalate: 'Escalamiento recomendado',
    info: 'Evento informativo',
    discard: 'Descartado',
};

// ---- VisibilityChip ----

function VisibilityChip({ v }: { v: 'internal' | 'tenant' | 'audit' }) {
    const map = {
        internal: {
            label: 'Interno',
            cls: 'bg-surface-3 text-fg-3 border-border',
        },
        tenant: {
            label: 'Tenant',
            cls: 'bg-primary/10 text-primary border-primary/30',
        },
        audit: {
            label: 'Auditoría',
            cls: 'bg-severity-high/10 text-severity-high border-severity-high/30',
        },
    };
    const { label, cls } = map[v];

    return (
        <span
            className={cn(
                'inline-flex rounded-[3px] border px-1.5 py-0.5 text-[9px] font-semibold tracking-[0.06em] uppercase',
                cls,
            )}
        >
            {label}
        </span>
    );
}

// ---- UserAvatar ----

function UserAvatar({
    initials,
    size = 24,
}: {
    initials: string;
    size?: number;
}) {
    return (
        <span
            className="inline-grid shrink-0 place-items-center rounded-full border border-border bg-surface-3 font-semibold text-fg-2"
            style={{
                width: size,
                height: size,
                fontSize: Math.max(9, size * 0.42),
            }}
        >
            {initials}
        </span>
    );
}

// ---- AiEvaluationCard ----

interface AiEvaluationCardProps {
    incident: IncidentDetail;
}

function AiEvaluationCard({ incident }: AiEvaluationCardProps) {
    const [showReasoning, setShowReasoning] = useState(false);

    return (
        <div className="relative overflow-hidden rounded-[6px] border border-ai-accent/35 bg-surface-1 p-3.5">
            {/* Left accent bar */}
            <div className="absolute inset-y-0 left-0 w-[3px] bg-ai-accent" />

            {/* Header */}
            <div className="mb-2 flex items-center justify-between gap-2">
                <span className="text-[10px] font-semibold tracking-[0.08em] text-ai-accent uppercase">
                    SAM · decisión IA
                </span>
                <span className="font-mono text-[10px] text-fg-3">
                    {incident.model} · {incident.latencyMs} ms
                </span>
            </div>

            {/* Decision */}
            <div className="mb-1 text-[14px] font-semibold text-fg-1">
                {DECISION_LABEL[incident.aiDecision]}
            </div>

            {/* Reason */}
            <p className="mb-2.5 text-[13px] leading-[1.5] text-fg-2">
                {incident.aiReason}
            </p>

            {/* Confidence bar */}
            <ConfidenceBar value={incident.aiConfidence} className="mb-3" />

            {/* Reasoning toggle */}
            <button
                type="button"
                className="mb-2 flex items-center gap-1 text-[12px] text-fg-2 hover:text-fg-1"
                onClick={() => setShowReasoning((v) => !v)}
            >
                {showReasoning ? (
                    <ChevronDown size={13} strokeWidth={1.75} />
                ) : (
                    <ChevronRight size={13} strokeWidth={1.75} />
                )}
                Cadena de razonamiento
            </button>

            {showReasoning && (
                <div className="mb-3 rounded-sm border border-border bg-surface-2 p-3 text-[12px] leading-[1.5] text-fg-2">
                    <ol className="list-inside list-decimal space-y-1">
                        <li>
                            Webhook recibido con firma válida · provider:{' '}
                            {incident.provider}
                        </li>
                        <li>
                            Tipo de evento: {incident.eventType} · activo:{' '}
                            {incident.asset}
                        </li>
                        <li>
                            Contexto operativo evaluado: ubicación{' '}
                            {incident.location}
                        </li>
                        <li>
                            Evaluación heurística + modelo IA →{' '}
                            {incident.aiDecision} (
                            {Math.round(incident.aiConfidence * 100)} %)
                        </li>
                        <li>
                            Regla de decisión aplicada → severidad{' '}
                            {incident.severity}
                        </li>
                    </ol>
                </div>
            )}

            {/* Actions */}
            <div className="flex flex-wrap gap-2">
                <Button size="sm" variant="default">
                    Confirmar
                </Button>
                <Button size="sm" variant="ghost">
                    Reclasificar
                </Button>
                <Button size="sm" variant="ghost">
                    Feedback
                </Button>
            </div>
        </div>
    );
}

// ---- Evidence tile icons ----

const EVIDENCE_ICON = {
    chart: BarChart2,
    video: Video,
    map: Map,
    payload: FileCode,
};

// ---- DetailCenter ----

interface DetailCenterProps {
    incident: IncidentDetail;
}

export function DetailCenter({ incident }: DetailCenterProps) {
    const [comment, setComment] = useState('');
    const [visibility, setVisibility] = useState<
        'internal' | 'tenant' | 'audit'
    >('internal');

    return (
        <div className="flex flex-col gap-5">
            {/* Description */}
            <section>
                <h3 className="mb-2 text-[10px] font-semibold tracking-[0.08em] text-fg-3 uppercase">
                    Descripción
                </h3>
                <p className="text-[13px] leading-[1.55] text-fg-1">
                    {incident.aiReason}
                </p>
            </section>

            {/* AI Evaluation */}
            <section>
                <h3 className="mb-2 text-[10px] font-semibold tracking-[0.08em] text-fg-3 uppercase">
                    Evaluación IA
                </h3>
                <AiEvaluationCard incident={incident} />
            </section>

            {/* Evidence */}
            {incident.evidence.length > 0 && (
                <section>
                    <h3 className="mb-2 text-[10px] font-semibold tracking-[0.08em] text-fg-3 uppercase">
                        Evidencia
                    </h3>
                    <div className="grid grid-cols-2 gap-1.5">
                        {incident.evidence.map((ev, idx) => {
                            const Icon = EVIDENCE_ICON[ev.type];

                            return (
                                <button
                                    key={idx}
                                    type="button"
                                    className="flex aspect-video flex-col items-center justify-center gap-1 rounded-sm border border-border bg-surface-2 text-fg-3 transition-colors hover:border-border-strong"
                                >
                                    <Icon
                                        size={22}
                                        strokeWidth={1.25}
                                        aria-hidden="true"
                                    />
                                    <span className="text-[11px] font-medium text-fg-1">
                                        {ev.label}
                                    </span>
                                    <span className="font-mono text-[10px] text-fg-3">
                                        {ev.sub}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </section>
            )}

            {/* Comments */}
            <section>
                <h3 className="mb-3 text-[10px] font-semibold tracking-[0.08em] text-fg-3 uppercase">
                    Comentarios{' '}
                    {incident.comments.length > 0
                        ? `· ${incident.comments.length}`
                        : ''}
                </h3>

                {incident.comments.length > 0 && (
                    <div className="mb-4 flex flex-col gap-3">
                        {incident.comments.map((c, idx) => (
                            <div key={idx} className="flex items-start gap-2.5">
                                <UserAvatar
                                    initials={c.authorInitials}
                                    size={24}
                                />
                                <div className="min-w-0 flex-1">
                                    <div className="mb-1 flex flex-wrap items-center gap-2">
                                        <span className="text-[12px] font-semibold text-fg-1">
                                            {c.authorName}
                                        </span>
                                        <VisibilityChip v={c.visibility} />
                                        <span className="text-[11px] text-fg-3">
                                            {c.relativeTime}
                                        </span>
                                    </div>
                                    <p className="text-[13px] leading-[1.5] text-fg-1">
                                        {c.body}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                {/* Compose */}
                <div
                    className="mt-2.5 grid items-center gap-2 rounded-[6px] border border-border bg-surface-1 p-2"
                    style={{ gridTemplateColumns: 'auto 1fr auto auto' }}
                >
                    <UserAvatar initials="MG" size={24} />
                    <input
                        type="text"
                        value={comment}
                        onChange={(e) => setComment(e.target.value)}
                        placeholder="Escribí un comentario…"
                        className="min-w-0 border-none bg-transparent text-[13px] text-fg-1 outline-none placeholder:text-fg-3"
                    />
                    <select
                        value={visibility}
                        onChange={(e) =>
                            setVisibility(
                                e.target.value as
                                    | 'internal'
                                    | 'tenant'
                                    | 'audit',
                            )
                        }
                        className="cursor-pointer border-none bg-transparent text-[12px] text-fg-2 outline-none"
                    >
                        <option value="internal">Interno</option>
                        <option value="tenant">Tenant</option>
                        <option value="audit">Auditoría</option>
                    </select>
                    <Button size="sm" variant="default">
                        Comentar
                    </Button>
                </div>
            </section>
        </div>
    );
}
