import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { Activity } from '@/components/sam/incident-detail/activity';
import { AiEvaluationCard } from '@/components/sam/incident-detail/ai-evaluation';
import { CommentsSection } from '@/components/sam/incident-detail/comments';
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
import { TEAM_BROADCAST_EVENT_NAME } from '@/hooks/use-team-broadcasts';
import type { TeamBroadcastDetail } from '@/hooks/use-team-broadcasts';
import type { IncidentShowProps } from '@/types/sam';

const RELOAD_DEBOUNCE_MS = 1500;

const DETAIL_PROPS = [
    'incident',
    'media',
    'mediaAssessments',
    'mediaRequests',
    'priorIncidents',
];

export default function IncidentShow() {
    const page = usePage();
    const { incident, media, mediaAssessments, mediaRequests, priorIncidents } =
        page.props as unknown as IncidentShowProps;
    const teamSlug =
        (
            page.props as unknown as {
                currentTeam?: { slug?: string | null } | null;
            }
        ).currentTeam?.slug ?? null;

    const timer = useRef<number | null>(null);

    const reloadDetail = () => {
        router.reload({ only: DETAIL_PROPS });
    };

    // Realtime: cualquier update broadcast de ESTE incidente (ack, escalación,
    // media evaluada vía B8) refresca los props, con debounce.
    useEffect(() => {
        const handler = (event: Event) => {
            const detail = (event as CustomEvent<TeamBroadcastDetail>).detail;

            if (detail?.event !== 'incidents.updated') {
                return;
            }

            const payload = detail.payload as { incident_id?: number };

            if (payload?.incident_id !== incident.incidentId) {
                return;
            }

            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }

            timer.current = window.setTimeout(() => {
                timer.current = null;
                reloadDetail();
            }, RELOAD_DEBOUNCE_MS);
        };

        window.addEventListener(TEAM_BROADCAST_EVENT_NAME, handler);

        return () => {
            window.removeEventListener(TEAM_BROADCAST_EVENT_NAME, handler);

            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        };
    }, [incident.incidentId]);

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
                            teamSlug
                                ? router.visit(`/${teamSlug}/incidents`)
                                : router.visit('/')
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

IncidentShow.layout = (props: {
    currentTeam?: { slug: string } | null;
    incident?: { incidentId: number; id: string } | null;
}) => ({
    breadcrumbs: [
        {
            title: 'Incidentes',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/incidents`
                : '/incidents',
        },
        ...(props.incident
            ? [
                  {
                      title: props.incident.id,
                      href:
                          props.currentTeam && props.incident
                              ? `/${props.currentTeam.slug}/incidents/${props.incident.incidentId}`
                              : '#',
                  },
              ]
            : []),
    ],
});
