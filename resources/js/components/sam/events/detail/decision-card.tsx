import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import { priorityLabel } from '@/lib/labels';
import type { EventDecision } from '@/types/events';

export function DecisionCard({ decision }: { decision: EventDecision | null }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0">Decisión</CardTitle>
                {decision?.decidedAt && (
                    <span className="sam-meta">
                        {formatDateTime(decision.decidedAt)}
                    </span>
                )}
            </CardHeader>
            <CardContent className="p-4">
                {decision === null ? (
                    <p className="text-sm text-fg-3">
                        Sin decisión. El motor de reglas decide en cuanto
                        termina la evaluación.
                    </p>
                ) : (
                    <div className="flex flex-col gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-md font-semibold text-fg-1">
                                {decision.outcomeLabel ?? decision.code ?? '—'}
                            </span>
                            {decision.requiresHumanReview && (
                                <span className="rounded-sm border border-severity-high/40 bg-severity-high/10 px-1.5 py-0.5 text-3xs font-semibold text-severity-high">
                                    Revisión humana
                                </span>
                            )}
                            <span className="text-2xs text-fg-3">
                                {decision.isAutomated ? 'automática' : 'manual'}
                                {decision.priorityLevel &&
                                    ` · prioridad ${priorityLabel(decision.priorityLevel).toLowerCase()}`}
                            </span>
                        </div>
                        {decision.reason && (
                            <p className="text-xs leading-relaxed text-fg-2">
                                {decision.reason}
                            </p>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
