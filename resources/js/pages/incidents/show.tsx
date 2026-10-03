import type { SharedPageProps } from '@inertiajs/core';
import { Head, router, usePage } from '@inertiajs/react';
import { Activity } from '@/components/sam/incident-detail/activity';
import { AiEvaluationCard } from '@/components/sam/incident-detail/ai-evaluation';
import { CommentsSection } from '@/components/sam/incident-detail/comments';
import { Communications } from '@/components/sam/incident-detail/communications';
import { DetailHeader } from '@/components/sam/incident-detail/detail-header';
import {
    EventFacts,
    EvidenceList,
    LinkedEvents,
    OperationalContext,
    ResolutionCard,
} from '@/components/sam/incident-detail/facts';
import { IncidentActionsProvider } from '@/components/sam/incident-detail/incident-actions-context';
import { Management } from '@/components/sam/incident-detail/management';
import { MediaStrip } from '@/components/sam/incident-detail/media-strip';
import { PriorIncidents } from '@/components/sam/incident-detail/prior-incidents';
import { useBroadcastReload } from '@/hooks/use-team-broadcasts';
import { home } from '@/routes';
import incidentRoutes from '@/routes/incidents';
import type { IncidentShowProps } from '@/types/sam';

const RELOAD_DEBOUNCE_MS = 1500;

const DETAIL_PROPS = [
    'incident',
    'media',
    'mediaAssessments',
    'mediaRequests',
    'mediaRetrieval',
    'communications',
    'priorIncidents',
];

export default function IncidentShow({
    incident,
    media,
    mediaAssessments,
    mediaRequests,
    mediaRetrieval,
    communications,
    priorIncidents,
}: IncidentShowProps) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? null;

    const reloadDetail = () => {
        router.reload({ only: DETAIL_PROPS });
    };

    // Realtime: updates of THIS incident (ack, escalation, media assessed) and
    // automation actions run on it refresh the detail, debounced.
    const forThisIncident = (payload: { incident_id: number | null }) =>
        payload.incident_id === incident.incidentId ? DETAIL_PROPS : null;

    useBroadcastReload(
        {
            'incidents.updated': forThisIncident,
            'action.executed': forThisIncident,
        },
        { debounceMs: RELOAD_DEBOUNCE_MS, resync: DETAIL_PROPS },
    );

    return (
        <>
            <Head title={`${incident.id} · ${incident.title}`} />

            <IncidentActionsProvider
                incident={incident}
                onMutated={reloadDetail}
            >
                {/* El ops shell es h-dvh overflow-hidden: la página es dueña
                    de su scroll. */}
                <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-y-auto">
                    <DetailHeader
                        incident={incident}
                        onClose={() =>
                            router.visit(
                                teamSlug
                                    ? incidentRoutes.index(teamSlug)
                                    : home(),
                            )
                        }
                    />

                    {/* Historia a la izquierda (evaluación → media → actividad
                        → comentarios), hechos y gestión a la derecha. Nada de
                        columnas que se quedan vacías mientras otra crece. */}
                    <div className="mx-auto grid w-full max-w-[1400px] min-w-0 gap-x-6 gap-y-5 p-4 lg:grid-cols-[minmax(0,5fr)_minmax(290px,2fr)] lg:p-5">
                        <div className="flex min-w-0 flex-col gap-6">
                            <AiEvaluationCard
                                incident={incident}
                                mediaSummary={incident.mediaSummary}
                            />
                            <MediaStrip
                                incidentId={incident.incidentId}
                                media={media}
                                assessments={mediaAssessments}
                                requests={mediaRequests}
                                retrieval={mediaRetrieval}
                                onMutated={reloadDetail}
                            />
                            <Activity incident={incident} />
                            <CommentsSection incident={incident} />
                        </div>

                        <div className="flex min-w-0 flex-col gap-6">
                            <Management incident={incident} />
                            <ResolutionCard incident={incident} />
                            <EventFacts incident={incident} />
                            <OperationalContext incident={incident} />
                            <EvidenceList incident={incident} />
                            <Communications
                                communications={communications}
                                teamSlug={teamSlug}
                            />
                            <PriorIncidents
                                priorIncidents={priorIncidents}
                                teamSlug={teamSlug}
                            />
                            <LinkedEvents incident={incident} />
                        </div>
                    </div>
                </div>
            </IncidentActionsProvider>
        </>
    );
}

IncidentShow.layout = (
    props: SharedPageProps & Partial<IncidentShowProps>,
) => ({
    breadcrumbs: [
        {
            title: 'Incidentes',
            href: props.currentTeam
                ? incidentRoutes.index.url(props.currentTeam.slug)
                : '#',
        },
        ...(props.incident
            ? [
                  {
                      title: props.incident.id,
                      href:
                          props.currentTeam && props.incident
                              ? incidentRoutes.show.url([
                                    props.currentTeam.slug,
                                    props.incident.incidentId,
                                ])
                              : '#',
                  },
              ]
            : []),
    ],
});
