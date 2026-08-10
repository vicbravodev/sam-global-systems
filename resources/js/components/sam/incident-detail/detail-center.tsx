import { usePage } from '@inertiajs/react';
import {
    BarChart2,
    ChevronDown,
    ChevronRight,
    FileCode,
    Loader2,
    Map,
    Video,
} from 'lucide-react';
import { useState } from 'react';
import { ConfidenceBar } from '@/components/sam';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type { AiDecision, IncidentDetail } from '@/types/sam';
import { useIncidentActions } from './incident-actions-context';
import type { CommentVisibilityUi } from './incident-actions-context';

// Sentinel para representar "sin selección" en los <Select> del DS: Radix
// no permite SelectItem con value="", así que un value vacío real
// (sin cambio) se traduce a/desde este string en el handler.
const NONE_OPTION = '__none__';

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
                'inline-flex rounded-sm border px-1.5 py-0.5 text-3xs font-semibold tracking-caps uppercase',
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

// ---- ReclassifyDialog ----

function ReclassifyDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { reclassify, reclassifyOptions, pending } = useIncidentActions();
    const [typeId, setTypeId] = useState<string>('');
    const [priorityId, setPriorityId] = useState<string>('');
    const busy = pending === 'reclassify';

    const submit = async () => {
        if (typeId === '') {
            return;
        }

        const ok = await reclassify(
            Number(typeId),
            priorityId === '' ? null : Number(priorityId),
        );

        if (ok) {
            onOpenChange(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Reclasificar incidente</DialogTitle>
                </DialogHeader>
                <div className="flex flex-col gap-3">
                    <label className="flex flex-col gap-1 text-xs text-fg-2">
                        Tipo
                        <Select value={typeId} onValueChange={setTypeId}>
                            <SelectTrigger className="h-9">
                                <SelectValue placeholder="Selecciona un tipo…" />
                            </SelectTrigger>
                            <SelectContent>
                                {reclassifyOptions.types.map((t) => (
                                    <SelectItem key={t.id} value={String(t.id)}>
                                        {t.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </label>
                    <label className="flex flex-col gap-1 text-xs text-fg-2">
                        Prioridad (opcional)
                        <Select
                            value={priorityId === '' ? NONE_OPTION : priorityId}
                            onValueChange={(value) =>
                                setPriorityId(
                                    value === NONE_OPTION ? '' : value,
                                )
                            }
                        >
                            <SelectTrigger className="h-9">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE_OPTION}>
                                    Sin cambio
                                </SelectItem>
                                {reclassifyOptions.priorities.map((p) => (
                                    <SelectItem key={p.id} value={String(p.id)}>
                                        {p.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </label>
                </div>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancelar
                    </Button>
                    <Button
                        size="sm"
                        onClick={() => void submit()}
                        disabled={busy || typeId === ''}
                    >
                        {busy ? (
                            <Loader2 size={13} className="animate-spin" />
                        ) : null}
                        Reclasificar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ---- FeedbackDialog (request AI re-evaluation) ----

function FeedbackDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { feedbackAi, pending } = useIncidentActions();
    const [reason, setReason] = useState('');
    const busy = pending === 'feedback-ai';

    const submit = async () => {
        if (reason.trim() === '') {
            return;
        }

        const ok = await feedbackAi(reason.trim());

        if (ok) {
            onOpenChange(false);
            setReason('');
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Feedback de la evaluación IA</DialogTitle>
                </DialogHeader>
                <p className="text-xs leading-normal text-fg-3">
                    Describe por qué la evaluación es incorrecta. SAM volverá a
                    evaluar el evento con tu feedback.
                </p>
                <textarea
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    rows={3}
                    placeholder="Motivo de la reevaluación…"
                    className="mt-1 resize-none rounded-md border border-border bg-surface-1 px-2.5 py-1.5 text-sm text-fg-1 outline-none placeholder:text-fg-3"
                />
                <DialogFooter>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancelar
                    </Button>
                    <Button
                        size="sm"
                        onClick={() => void submit()}
                        disabled={busy || reason.trim() === ''}
                    >
                        {busy ? (
                            <Loader2 size={13} className="animate-spin" />
                        ) : null}
                        Enviar feedback
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ---- AiEvaluationCard ----

interface AiEvaluationCardProps {
    incident: IncidentDetail;
}

function AiEvaluationCard({ incident }: AiEvaluationCardProps) {
    const [showReasoning, setShowReasoning] = useState(false);
    const [reclassifyOpen, setReclassifyOpen] = useState(false);
    const [feedbackOpen, setFeedbackOpen] = useState(false);
    const { confirmAi, pending } = useIncidentActions();

    return (
        <div className="relative overflow-hidden rounded-md border border-ai-accent/35 bg-surface-1 p-3.5">
            {/* Left accent bar */}
            <div className="absolute inset-y-0 left-0 w-[3px] bg-ai-accent" />

            {/* Header */}
            <div className="mb-2 flex items-center justify-between gap-2">
                <span className="text-3xs font-semibold tracking-caps text-ai-accent uppercase">
                    SAM · decisión IA
                </span>
                <span className="font-mono text-3xs text-fg-3">
                    {incident.model} · {incident.latencyMs} ms
                </span>
            </div>

            {/* Decision */}
            <div className="mb-1 text-base font-semibold text-fg-1">
                {DECISION_LABEL[incident.aiDecision]}
            </div>

            {/* Reason */}
            <p className="mb-2.5 text-sm leading-normal text-fg-2">
                {incident.aiReason}
            </p>

            {/* Confidence bar */}
            <ConfidenceBar value={incident.aiConfidence} className="mb-3" />

            {/* Reasoning toggle */}
            <button
                type="button"
                className="mb-2 flex items-center gap-1 text-xs text-fg-2 hover:text-fg-1"
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
                <div className="mb-3 rounded-sm border border-border bg-surface-2 p-3 text-xs leading-normal text-fg-2">
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
                <Button
                    size="sm"
                    variant="default"
                    onClick={() => void confirmAi()}
                    disabled={pending === 'confirm-ai'}
                >
                    {pending === 'confirm-ai' ? (
                        <Loader2 size={12} className="animate-spin" />
                    ) : null}
                    Confirmar
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => setReclassifyOpen(true)}
                >
                    Reclasificar
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => setFeedbackOpen(true)}
                >
                    Feedback
                </Button>
            </div>

            <ReclassifyDialog
                open={reclassifyOpen}
                onOpenChange={setReclassifyOpen}
            />
            <FeedbackDialog
                open={feedbackOpen}
                onOpenChange={setFeedbackOpen}
            />
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

// ---- CommentComposer ----

function CommentComposer() {
    const page = usePage();
    const getInitials = useInitials();
    const { addComment, pending } = useIncidentActions();
    const [comment, setComment] = useState('');
    const [visibility, setVisibility] =
        useState<CommentVisibilityUi>('internal');
    const busy = pending === 'comment';

    const currentUserName =
        (page.props.auth?.user?.name as string | undefined) ?? null;
    const myInitials = currentUserName ? getInitials(currentUserName) : '··';

    const submit = async () => {
        if (comment.trim() === '') {
            return;
        }

        const ok = await addComment(comment.trim(), visibility);

        if (ok) {
            setComment('');
        }
    };

    return (
        <div className="mt-2.5 flex flex-wrap items-center gap-2 rounded-md border border-border bg-surface-1 p-2">
            {/* Avatar + input share a shrinkable group so they never force
                the select/button group to overflow; if the container is too
                narrow for everything on one line, the select/button group
                wraps to its own row instead of getting clipped. */}
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <UserAvatar initials={myInitials} size={24} />
                <input
                    type="text"
                    value={comment}
                    onChange={(e) => setComment(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            void submit();
                        }
                    }}
                    placeholder="Escribe un comentario…"
                    className="min-w-0 flex-1 border-none bg-transparent text-sm text-fg-1 outline-none placeholder:text-fg-3"
                />
            </div>
            <div className="flex shrink-0 items-center gap-2">
                <Select
                    value={visibility}
                    onValueChange={(value) =>
                        setVisibility(value as CommentVisibilityUi)
                    }
                >
                    <SelectTrigger className="h-9">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="internal">Interno</SelectItem>
                        <SelectItem value="tenant">Tenant</SelectItem>
                        <SelectItem value="audit">Auditoría</SelectItem>
                    </SelectContent>
                </Select>
                <Button
                    size="sm"
                    variant="default"
                    onClick={() => void submit()}
                    disabled={busy || comment.trim() === ''}
                >
                    {busy ? (
                        <Loader2 size={12} className="animate-spin" />
                    ) : null}
                    Comentar
                </Button>
            </div>
        </div>
    );
}

// ---- DetailCenter ----

interface DetailCenterProps {
    incident: IncidentDetail;
}

export function DetailCenter({ incident }: DetailCenterProps) {
    return (
        <div className="flex min-w-0 flex-col gap-5">
            {/* Description */}
            <section>
                <h3 className="mb-2 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                    Descripción
                </h3>
                <p className="text-sm leading-[1.55] text-fg-1">
                    {incident.aiReason}
                </p>
            </section>

            {/* AI Evaluation */}
            <section>
                <h3 className="mb-2 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                    Evaluación IA
                </h3>
                <AiEvaluationCard incident={incident} />
            </section>

            {/* Evidence */}
            {incident.evidence.length > 0 && (
                <section>
                    <h3 className="mb-2 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                        Evidencia
                    </h3>
                    <div className="grid grid-cols-2 gap-1.5">
                        {incident.evidence.map((ev, idx) => {
                            const Icon = EVIDENCE_ICON[ev.type];

                            return (
                                <div
                                    key={idx}
                                    className="flex aspect-video flex-col items-center justify-center gap-1 rounded-sm border border-border bg-surface-2 text-fg-3"
                                >
                                    <Icon
                                        size={22}
                                        strokeWidth={1.25}
                                        aria-hidden="true"
                                    />
                                    <span className="text-2xs font-medium text-fg-1">
                                        {ev.label}
                                    </span>
                                    <span className="font-mono text-3xs text-fg-3">
                                        {ev.sub}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </section>
            )}

            {/* Comments */}
            <section>
                <h3 className="mb-3 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
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
                                        <span className="text-xs font-semibold text-fg-1">
                                            {c.authorName}
                                        </span>
                                        <VisibilityChip v={c.visibility} />
                                        <span className="text-2xs text-fg-3">
                                            {c.relativeTime}
                                        </span>
                                    </div>
                                    <p className="text-sm leading-normal text-fg-1">
                                        {c.body}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                <CommentComposer />
            </section>
        </div>
    );
}
