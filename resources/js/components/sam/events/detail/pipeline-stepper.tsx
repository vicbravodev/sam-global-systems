import { Check, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import type {
    EventDecision,
    EventDetail,
    EventEvaluation,
    EventIncident,
} from '@/types/events';

type StepState = 'done' | 'active' | 'pending' | 'failed' | 'skipped';

export function PipelineStepper({
    event,
    evaluation,
    decision,
    incident,
}: {
    event: EventDetail;
    evaluation: EventEvaluation | null;
    decision: EventDecision | null;
    incident: EventIncident | null;
}) {
    const failed = event.status === 'failed';
    const unmapped = event.status === 'unmapped';

    const steps: {
        key: string;
        label: string;
        state: StepState;
        note: string | null;
    }[] = [
        {
            key: 'ingest',
            label: 'Ingesta',
            state: 'done',
            note: event.rawEventId !== null ? `raw #${event.rawEventId}` : null,
        },
        {
            key: 'normalize',
            label: 'Normalización',
            state: unmapped ? 'failed' : 'done',
            note: unmapped ? 'sin regla' : (event.eventTypeCode ?? null),
        },
        {
            key: 'context',
            label: 'Contexto',
            state:
                event.status === 'enriched'
                    ? 'done'
                    : event.status === 'enrichment_pending'
                      ? 'active'
                      : failed
                        ? 'failed'
                        : unmapped
                          ? 'skipped'
                          : 'pending',
            note:
                event.status === 'enrichment_pending'
                    ? 'en curso'
                    : event.status === 'enriched'
                      ? 'enriquecido'
                      : null,
        },
        {
            key: 'ai',
            label: 'Evaluación IA',
            state: evaluation?.isPlaceholder
                ? 'skipped'
                : evaluation
                  ? 'done'
                  : failed || unmapped
                    ? 'skipped'
                    : 'pending',
            note: evaluation?.isPlaceholder
                ? (evaluation.placeholderLabel ?? 'Sin evaluación IA')
                : (evaluation?.classificationLabel ?? null),
        },
        {
            key: 'decision',
            label: 'Decisión',
            state: decision
                ? 'done'
                : failed || unmapped
                  ? 'skipped'
                  : 'pending',
            note: decision?.outcomeLabel ?? null,
        },
        {
            key: 'incident',
            label: 'Incidente',
            state: incident
                ? 'done'
                : decision
                  ? 'skipped'
                  : failed || unmapped
                    ? 'skipped'
                    : 'pending',
            note: incident
                ? incident.statusLabel
                : decision
                  ? 'no aplicó'
                  : null,
        },
    ];

    return (
        <ol className="scrollbar-none flex items-stretch gap-0 overflow-x-auto rounded-lg border border-border bg-surface-1">
            {steps.map((step, index) => {
                const last = index === steps.length - 1;

                return (
                    <li
                        key={step.key}
                        className={cn(
                            'flex min-w-[140px] flex-1 items-center gap-2.5 px-4 py-3',
                            !last && 'border-r border-border',
                        )}
                    >
                        <span
                            className={cn(
                                'grid size-6 shrink-0 place-items-center rounded-full border text-3xs font-semibold',
                                step.state === 'done' &&
                                    'border-severity-low/40 bg-severity-low/15 text-severity-low',
                                step.state === 'active' &&
                                    'border-severity-medium/40 bg-severity-medium/15 text-severity-medium motion-safe:animate-pulse',
                                step.state === 'failed' &&
                                    'border-severity-critical/40 bg-severity-critical/15 text-severity-critical',
                                step.state === 'pending' &&
                                    'border-border bg-surface-2 text-fg-3',
                                step.state === 'skipped' &&
                                    'border-dashed border-border bg-transparent text-fg-disabled',
                            )}
                            aria-hidden="true"
                        >
                            {step.state === 'done' ? (
                                <Check size={12} />
                            ) : step.state === 'failed' ? (
                                '!'
                            ) : (
                                index + 1
                            )}
                        </span>
                        <span className="flex min-w-0 flex-col">
                            <span
                                className={cn(
                                    'text-2xs font-semibold tracking-label',
                                    step.state === 'skipped'
                                        ? 'text-fg-disabled'
                                        : 'text-fg-1',
                                )}
                            >
                                {step.label}
                            </span>
                            <span className="truncate text-3xs text-fg-3">
                                {step.note ??
                                    (step.state === 'pending'
                                        ? 'pendiente'
                                        : step.state === 'skipped'
                                          ? 'omitido'
                                          : '')}
                            </span>
                        </span>
                        {!last && (
                            <ChevronRight
                                size={12}
                                className="ml-auto shrink-0 text-fg-disabled"
                                aria-hidden="true"
                            />
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
