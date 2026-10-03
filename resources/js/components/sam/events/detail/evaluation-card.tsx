import { Sparkles } from 'lucide-react';
import { ConfidenceBar } from '@/components/sam/confidence-bar';
import {
    DescriptionItem,
    DescriptionList,
} from '@/components/sam/description-list';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import { actionLabel, priorityLabel } from '@/lib/labels';
import { cn } from '@/lib/utils';
import type { EventEvaluation } from '@/types/events';

const MODE_LABELS: Record<string, string> = {
    rules_only: 'Solo reglas',
    ai_text: 'IA (texto)',
    multimodal: 'IA multimodal',
    hybrid: 'Híbrido',
    deferred_pending_media: 'Diferido, esperando media',
};

export function EvaluationCard({
    evaluation,
}: {
    evaluation: EventEvaluation | null;
}) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <Sparkles size={15} className="text-ai-accent" /> Evaluación
                    IA
                </CardTitle>
                {evaluation && (
                    <span className="sam-meta">
                        v{evaluation.version}
                        {evaluation.mode &&
                            ` · ${MODE_LABELS[evaluation.mode] ?? evaluation.mode}`}
                    </span>
                )}
            </CardHeader>
            <CardContent className="p-4">
                {evaluation === null ? (
                    <p className="text-sm text-fg-3">
                        Sin evaluación todavía. La IA analiza el evento en
                        cuanto el contexto (posición, historial, media) está
                        listo.
                    </p>
                ) : evaluation.isPlaceholder ? (
                    <div className="flex flex-col gap-1">
                        <p className="text-sm font-medium text-fg-1">
                            {evaluation.placeholderLabel ?? 'Sin evaluación IA'}
                        </p>
                        <p className="text-sm text-fg-3">
                            Ningún modelo de IA analizó este evento, así que no
                            hay veredicto, confianza ni riesgo que mostrar.
                        </p>
                    </div>
                ) : (
                    <div className="flex flex-col gap-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <span
                                className={cn(
                                    'rounded-sm border px-2 py-1 text-xs font-semibold',
                                    evaluation.isRealEvent === true
                                        ? 'border-severity-critical/40 bg-severity-critical/10 text-severity-critical'
                                        : evaluation.isRealEvent === false
                                          ? 'border-severity-low/40 bg-severity-low/10 text-severity-low'
                                          : 'border-ai-accent/40 bg-ai-accent-bg text-ai-accent',
                                )}
                            >
                                {evaluation.classificationLabel ??
                                    evaluation.classification ??
                                    'Sin clasificar'}
                            </span>
                            {evaluation.priorityLevel && (
                                <span className="text-2xs text-fg-3">
                                    prioridad{' '}
                                    <span className="text-fg-1">
                                        {priorityLabel(
                                            evaluation.priorityLevel,
                                        )}
                                    </span>
                                </span>
                            )}
                            {evaluation.requiresAction && (
                                <span className="rounded-sm border border-severity-high/40 bg-severity-high/10 px-1.5 py-0.5 text-3xs font-semibold text-severity-high">
                                    Requiere acción
                                </span>
                            )}
                        </div>
                        <DescriptionList className="gap-3">
                            <DescriptionItem
                                label="Confianza"
                                valueClassName="mt-0.5"
                            >
                                {evaluation.confidenceScore !== null ? (
                                    <ConfidenceBar
                                        value={evaluation.confidenceScore}
                                    />
                                ) : (
                                    '—'
                                )}
                            </DescriptionItem>
                            <DescriptionItem
                                label="Riesgo estimado"
                                mono
                                valueClassName="mt-0.5 tabular-nums"
                            >
                                {evaluation.riskScore !== null
                                    ? `${Math.round(evaluation.riskScore * 100)} / 100`
                                    : '—'}
                            </DescriptionItem>
                            {evaluation.recommendedAction && (
                                <DescriptionItem
                                    label="Acción recomendada"
                                    className="col-span-2"
                                >
                                    {actionLabel(evaluation.recommendedAction)}
                                </DescriptionItem>
                            )}
                        </DescriptionList>
                        {evaluation.explanation && (
                            <p className="rounded-md border border-ai-accent/30 bg-ai-accent-bg/60 p-3 text-xs leading-relaxed text-fg-2">
                                {evaluation.explanation}
                            </p>
                        )}
                        {evaluation.evaluatedAt && (
                            <span className="text-3xs text-fg-3">
                                evaluado{' '}
                                {formatDateTime(evaluation.evaluatedAt)}
                            </span>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
