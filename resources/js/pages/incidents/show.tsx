import { Head, Link, router, usePage } from '@inertiajs/react';
import { History } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { DetailCenter } from '@/components/sam/incident-detail/detail-center';
import { DetailHeader } from '@/components/sam/incident-detail/detail-header';
import { DetailSide } from '@/components/sam/incident-detail/detail-side';
import { DetailTimeline } from '@/components/sam/incident-detail/detail-timeline';
import { IncidentActionsProvider } from '@/components/sam/incident-detail/incident-actions-context';
import { MediaGallery } from '@/components/sam/incident-detail/media-gallery';
import { SeverityBadge } from '@/components/sam/severity-badge';
import type { Severity } from '@/components/sam/severity-badge';
import { Badge } from '@/components/ui/badge';
import { TEAM_BROADCAST_EVENT_NAME } from '@/hooks/use-team-broadcasts';
import type { TeamBroadcastDetail } from '@/hooks/use-team-broadcasts';
import type { IncidentShowProps, PriorIncidentSummary } from '@/types/sam';

const RELOAD_DEBOUNCE_MS = 1500;

const DETAIL_PROPS = [
    'incident',
    'media',
    'mediaAssessments',
    'mediaRequests',
    'priorIncidents',
];

const RELATION_LABEL: Record<string, string> = {
    same_asset_open_incident: 'Mismo activo',
    same_driver_recent_incident: 'Mismo conductor',
    same_location_cluster: 'Misma zona',
    probable_followup: 'Probable seguimiento',
    duplicate_operational_case: 'Caso duplicado',
    prior_similar_incident: 'Incidente similar previo',
};

const SEVERITY_LEVELS: Severity[] = [
    'critical',
    'high',
    'medium',
    'low',
    'info',
];

function PriorIncidentsCard({
    priorIncidents,
    teamSlug,
}: {
    priorIncidents: PriorIncidentSummary[];
    teamSlug: string | null;
}) {
    return (
        <section className="rounded-lg border border-border bg-surface-1 p-4">
            <h3 className="mb-3 flex items-center gap-1.5 text-sm font-semibold tracking-caps text-fg-1 uppercase">
                <History size={13} strokeWidth={1.5} />
                Historial relacionado
            </h3>
            {priorIncidents.length === 0 ? (
                <p className="text-xs text-fg-3">
                    Sin incidentes relacionados.
                </p>
            ) : (
                <ul className="flex flex-col gap-2">
                    {priorIncidents.map((prior) => (
                        <li
                            key={prior.incidentId}
                            className="rounded-md border border-border bg-surface-2 p-2.5"
                        >
                            <div className="mb-1 flex items-center gap-1.5">
                                {prior.severity &&
                                    SEVERITY_LEVELS.includes(
                                        prior.severity as Severity,
                                    ) && (
                                        <SeverityBadge
                                            level={prior.severity as Severity}
                                        />
                                    )}
                                <Badge
                                    variant="outline"
                                    className="text-3xs text-fg-3"
                                >
                                    {RELATION_LABEL[prior.relationType ?? ''] ??
                                        'Relacionado'}
                                </Badge>
                            </div>
                            {teamSlug ? (
                                <Link
                                    href={`/${teamSlug}/incidents/${prior.incidentId}`}
                                    className="text-xs font-medium text-fg-1 hover:underline"
                                >
                                    {prior.title}
                                </Link>
                            ) : (
                                <span className="text-xs font-medium text-fg-1">
                                    {prior.title}
                                </span>
                            )}
                            <div className="mt-0.5 text-2xs text-fg-3">
                                {prior.status ?? '—'}
                                {prior.openedAt &&
                                    ` · ${new Date(prior.openedAt).toLocaleString('es')}`}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

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

    // Realtime: any update broadcast for THIS incident (ack, escalation,
    // media assessed via B8) refreshes the page props, debounced.
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
                {/* The ops shell is h-dvh overflow-hidden: the page owns its scroll. */}
                <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-y-auto">
                    <DetailHeader
                        incident={incident}
                        onClose={() =>
                            teamSlug
                                ? router.visit(`/${teamSlug}/incidents`)
                                : router.visit('/')
                        }
                    />

                    <div className="grid min-w-0 gap-4 p-4 lg:[grid-template-columns:minmax(240px,1fr)_minmax(0,2fr)_minmax(280px,1.1fr)]">
                        {/* Col 1: timeline */}
                        <div className="min-w-0">
                            <DetailTimeline incident={incident} />
                        </div>

                        {/* Col 2: media + comments/evidence */}
                        <div className="flex min-w-0 flex-col gap-4">
                            <MediaGallery
                                incidentId={incident.incidentId}
                                media={media}
                                assessments={mediaAssessments}
                                requests={mediaRequests}
                                onMutated={reloadDetail}
                            />
                            <DetailCenter incident={incident} />
                        </div>

                        {/* Col 3: context + prior incidents */}
                        <div className="flex min-w-0 flex-col gap-4">
                            <DetailSide incident={incident} />
                            <PriorIncidentsCard
                                priorIncidents={priorIncidents}
                                teamSlug={teamSlug}
                            />
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
