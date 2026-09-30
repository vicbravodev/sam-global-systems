import { Play } from 'lucide-react';
import type { IncidentDetail } from '@/types/sam';
import { Activity } from './activity';
import { AiEvaluationCard } from './ai-evaluation';
import { CommentsSection } from './comments';
import { DetailHeader } from './detail-header';
import {
    EventFacts,
    LinkedEvents,
    OperationalContext,
    ResolutionCard,
} from './facts';
import { IncidentActionsProvider } from './incident-actions-context';
import { Management } from './management';
import { MediaVerdictChips } from './media-verdict';

interface IncidentDetailPanelProps {
    incident: IncidentDetail;
    onClose: () => void;
    onMutated: () => void;
    /** URL del detalle completo, CTA "Abrir detalle" (F9). */
    detailHref?: string;
}

/** Miniaturas agregadas del payload JSON: adelanto visual sin cargar la
    galería completa (esa vive en la página de detalle). */
function MediaPreviewStrip({ incident }: { incident: IncidentDetail }) {
    const summary = incident.mediaSummary;

    if (!summary || summary.total === 0) {
        return null;
    }

    const thumbs = summary.thumbnails.filter((thumb) => thumb.url !== null);

    return (
        <section>
            <h3 className="mb-2 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                Media del evento
                <span className="ml-1.5 font-mono text-fg-2 normal-case">
                    {summary.total}
                </span>
            </h3>
            {thumbs.length > 0 && (
                <div className="mb-2 flex gap-2 overflow-x-auto pb-1">
                    {thumbs.map((thumb) => (
                        <span
                            key={thumb.id}
                            className="relative h-20 w-32 shrink-0 overflow-hidden rounded-md border border-border bg-surface-2"
                        >
                            <img
                                src={thumb.url ?? undefined}
                                alt={`Media #${thumb.id}`}
                                loading="lazy"
                                className="h-full w-full object-cover"
                            />
                            {(thumb.mediaType === 'clip' ||
                                thumb.mediaType === 'video') && (
                                <span className="absolute inset-0 grid place-items-center">
                                    <span className="grid size-6 place-items-center rounded-full bg-black/60 text-white">
                                        <Play
                                            size={11}
                                            strokeWidth={2}
                                            className="ml-0.5"
                                        />
                                    </span>
                                </span>
                            )}
                        </span>
                    ))}
                    {summary.total > thumbs.length && (
                        <span className="grid h-20 w-16 shrink-0 place-items-center rounded-md border border-border bg-surface-2 font-mono text-xs text-fg-3">
                            +{summary.total - thumbs.length}
                        </span>
                    )}
                </div>
            )}
            <MediaVerdictChips summary={summary} />
        </section>
    );
}

/**
 * Preview del incidente en la bandeja: resumen operativo en UNA columna con
 * UN solo scroll. La vista de trabajo profunda es la página de detalle.
 */
export function IncidentDetailPanel({
    incident,
    onClose,
    onMutated,
    detailHref,
}: IncidentDetailPanelProps) {
    return (
        <IncidentActionsProvider incident={incident} onMutated={onMutated}>
            <div className="flex min-w-0 flex-col overflow-hidden border-l border-border bg-background">
                <DetailHeader
                    incident={incident}
                    onClose={onClose}
                    detailHref={detailHref}
                    variant="panel"
                />

                <div className="min-h-0 flex-1 overflow-y-auto">
                    <div className="flex flex-col gap-5 p-3.5">
                        <AiEvaluationCard incident={incident} compact />
                        <MediaPreviewStrip incident={incident} />
                        <Management incident={incident} />
                        <ResolutionCard incident={incident} />
                        <EventFacts incident={incident} />
                        <OperationalContext incident={incident} />
                        <Activity incident={incident} limit={6} />
                        <LinkedEvents incident={incident} />
                        <CommentsSection incident={incident} />
                    </div>
                </div>
            </div>
        </IncidentActionsProvider>
    );
}
