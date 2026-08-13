import { ChevronDown, ChevronRight, Loader2, Sparkles } from 'lucide-react';
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
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    AiDecision,
    IncidentDetail,
    IncidentMediaSummary,
} from '@/types/sam';
import { useIncidentActions } from './incident-actions-context';
import { MediaVerdictChips } from './media-verdict';

// Sentinel para "sin selección" en <Select>: Radix no permite value="".
const NONE_OPTION = '__none__';

const DECISION_LABEL: Record<AiDecision, string> = {
    incident: 'Incidente confirmado',
    escalate: 'Escalamiento recomendado',
    info: 'Evento informativo',
    discard: 'Descartado',
};

const MODE_LABEL: Record<string, string> = {
    rules_only: 'solo reglas',
    ai_text: 'texto',
    multimodal: 'multimodal',
    hybrid: 'híbrida',
    deferred_pending_media: 'esperando media',
};

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

// ---- FeedbackDialog (solicitar reevaluación) ----

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

// ---- RiskIndicator ----

function RiskIndicator({ value }: { value: number }) {
    const pct = Math.round(value * 100);
    const tone =
        value >= 0.7
            ? 'text-severity-critical'
            : value >= 0.4
              ? 'text-severity-high'
              : 'text-fg-2';

    return (
        <span
            className="inline-flex shrink-0 items-baseline gap-1 whitespace-nowrap"
            title="Puntaje de riesgo estimado por la IA"
        >
            <span className="text-2xs text-fg-3">Riesgo</span>
            <span className={cn('font-mono text-xs font-semibold', tone)}>
                {pct} %
            </span>
        </span>
    );
}

// ---- AiEvaluationCard ----

interface AiEvaluationCardProps {
    incident: IncidentDetail;
    /** Preferir la prop dedicada; cae al campo embebido del payload JSON. */
    mediaSummary?: IncidentMediaSummary | null;
    /** Panel de la bandeja: paddings y tipografía más compactos. */
    compact?: boolean;
}

export function AiEvaluationCard({
    incident,
    mediaSummary,
    compact = false,
}: AiEvaluationCardProps) {
    const [showReasoning, setShowReasoning] = useState(false);
    const [reclassifyOpen, setReclassifyOpen] = useState(false);
    const [feedbackOpen, setFeedbackOpen] = useState(false);
    const { confirmAi, pending } = useIncidentActions();

    const summary = mediaSummary ?? incident.mediaSummary ?? null;
    const modeLabel = incident.aiMode
        ? (MODE_LABEL[incident.aiMode] ?? incident.aiMode)
        : null;
    const steps = incident.aiReasoningSteps ?? [];

    return (
        <section
            className={cn(
                'rounded-lg border border-ai-accent/30 bg-surface-1',
                compact ? 'p-3' : 'p-4',
            )}
        >
            {/* Header */}
            <div className="mb-2 flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
                <span className="inline-flex items-center gap-1.5 text-3xs font-semibold tracking-caps text-ai-accent uppercase">
                    <Sparkles size={11} strokeWidth={1.75} />
                    Evaluación IA
                </span>
                <span
                    className="font-mono text-3xs text-fg-3"
                    title={
                        incident.aiEvaluatedAt
                            ? `Evaluado el ${formatDateTime(incident.aiEvaluatedAt)}`
                            : undefined
                    }
                >
                    {incident.model}
                    {modeLabel ? ` · ${modeLabel}` : ''}
                </span>
            </div>

            {/* Decision + confidence + risk */}
            <div
                className={cn(
                    'mb-1 font-semibold text-fg-1',
                    compact ? 'text-sm' : 'text-base',
                )}
            >
                {DECISION_LABEL[incident.aiDecision]}
            </div>

            <p
                className={cn(
                    'mb-3 leading-normal text-fg-2',
                    compact ? 'text-xs' : 'text-sm',
                )}
            >
                {incident.aiReason}
            </p>

            <div className="mb-3 flex items-center gap-3">
                <ConfidenceBar
                    value={incident.aiConfidence}
                    className="min-w-0 flex-1"
                />
                {incident.aiRiskScore !== null && (
                    <RiskIndicator value={incident.aiRiskScore} />
                )}
            </div>

            {/* Veredicto visual: la conclusión de las imágenes, junto a la
                evaluación en vez de perdida en el timeline. */}
            {summary && summary.total > 0 && (
                <div className="mb-3 rounded-md border border-border bg-surface-2 p-2.5">
                    <div className="mb-1.5 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                        Análisis visual · {summary.assessed} de {summary.total}{' '}
                        medias evaluadas
                    </div>
                    <MediaVerdictChips summary={summary} />
                </div>
            )}

            {/* Cadena de razonamiento REAL (signals_json.reasoning_steps);
                oculta si el modelo no la produjo. */}
            {steps.length > 0 && (
                <>
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
                        Cadena de razonamiento · {steps.length}{' '}
                        {steps.length === 1 ? 'paso' : 'pasos'}
                    </button>

                    {showReasoning && (
                        <ol className="mb-3 list-inside list-decimal space-y-1.5 rounded-md border border-border bg-surface-2 p-3 text-xs leading-normal text-fg-2">
                            {steps.map((step, idx) => (
                                <li key={idx}>{step}</li>
                            ))}
                        </ol>
                    )}
                </>
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
        </section>
    );
}
