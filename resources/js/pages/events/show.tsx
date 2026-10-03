import { Head, usePage } from '@inertiajs/react';
import { DecisionCard } from '@/components/sam/events/detail/decision-card';
import { EvaluationCard } from '@/components/sam/events/detail/evaluation-card';
import { EventHero } from '@/components/sam/events/detail/event-hero';
import { FactsCard } from '@/components/sam/events/detail/facts-card';
import { IncidentCard } from '@/components/sam/events/detail/incident-card';
import { JsonBlock } from '@/components/sam/events/detail/json-block';
import { MediaCard } from '@/components/sam/events/detail/media-card';
import { PipelineStepper } from '@/components/sam/events/detail/pipeline-stepper';
import type { EventShowProps } from '@/types/events';

export default function EventShow() {
    const page = usePage();
    const { event, evaluation, decision, incident, media } =
        page.props as unknown as EventShowProps;
    const teamSlug = page.props.currentTeam?.slug ?? null;

    const unmapped = event.status === 'unmapped';

    return (
        <>
            <Head
                title={`${event.eventType ?? `Evento #${event.id}`} - Eventos`}
            />
            <div className="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 md:p-6">
                <EventHero event={event} teamSlug={teamSlug} />

                {unmapped && (
                    <div className="rounded-md border border-severity-high/40 bg-severity-high/10 px-3 py-2 text-xs text-fg-2">
                        Este evento no coincidió con ninguna regla de mapeo.
                        Revisa el payload crudo y añade la regla en
                        Normalización para que los siguientes entren al
                        pipeline.
                    </div>
                )}

                <PipelineStepper
                    event={event}
                    evaluation={evaluation}
                    decision={decision}
                    incident={incident}
                />

                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                    <div className="flex min-w-0 flex-col gap-4">
                        <FactsCard event={event} />
                        <MediaCard media={media} />
                        <JsonBlock
                            title="Payload normalizado"
                            data={event.payload}
                        />
                        <JsonBlock title="Contexto" data={event.context} />
                        <JsonBlock
                            title={`Payload crudo${event.rawEventId !== null ? ` (raw #${event.rawEventId})` : ''}`}
                            data={event.rawPayload}
                        />
                    </div>
                    <div className="flex min-w-0 flex-col gap-4">
                        <EvaluationCard evaluation={evaluation} />
                        <DecisionCard decision={decision} />
                        <IncidentCard incident={incident} teamSlug={teamSlug} />
                    </div>
                </div>
            </div>
        </>
    );
}

EventShow.layout = (props: {
    currentTeam?: { slug: string } | null;
    event?: { id: number; eventType?: string | null } | null;
}) => ({
    breadcrumbs: [
        {
            title: 'Eventos',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/events`
                : '/events',
        },
        ...(props.event
            ? [
                  {
                      title: props.event.eventType ?? `#${props.event.id}`,
                      href:
                          props.currentTeam && props.event
                              ? `/${props.currentTeam.slug}/events/${props.event.id}`
                              : '#',
                  },
              ]
            : []),
    ],
});
