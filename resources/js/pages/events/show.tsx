import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

interface EventDetail {
    id: number;
    occurredAt: string | null;
    processedAt: string | null;
    status: string | null;
    eventType: string | null;
    eventTypeCode: string | null;
    category: string | null;
    severityLabel: string | null;
    severityColor: string | null;
    asset: string | null;
    driver: string | null;
    provider: string | null;
    payload: Record<string, unknown> | null;
    context: Record<string, unknown> | null;
    rawPayload: Record<string, unknown> | null;
    rawEventId: number | null;
}

interface EventShowProps {
    event: EventDetail;
    evaluation: {
        id: number;
        version: number;
        classification: string | null;
        confidenceScore: number | null;
        riskScore: number | null;
        priorityLevel: string | null;
        mode: string | null;
    } | null;
    decision: {
        id: number;
        code: string | null;
        reason: string | null;
        requiresHumanReview: boolean;
        decidedAt: string | null;
    } | null;
    incident: {
        id: number;
        title: string;
        status: string | null;
        severity: string | null;
    } | null;
    media: {
        id: number;
        mediaType: string | null;
        url: string | null;
        thumbnailUrl: string | null;
        capturedAt: string | null;
    }[];
}

function JsonBlock({
    title,
    data,
}: {
    title: string;
    data: Record<string, unknown> | null;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm uppercase">{title}</CardTitle>
            </CardHeader>
            <CardContent>
                {data === null || Object.keys(data).length === 0 ? (
                    <p className="text-xs text-fg-3">Sin datos.</p>
                ) : (
                    <pre className="max-h-72 overflow-auto rounded-md bg-surface-2 p-3 font-mono text-2xs leading-relaxed text-fg-2">
                        {JSON.stringify(data, null, 2)}
                    </pre>
                )}
            </CardContent>
        </Card>
    );
}

export default function EventShow() {
    const page = usePage();
    const { event, evaluation, decision, incident, media } =
        page.props as unknown as EventShowProps;
    const teamSlug =
        (
            page.props as unknown as {
                currentTeam?: { slug?: string | null } | null;
            }
        ).currentTeam?.slug ?? null;

    const unmapped = event.status === 'unmapped';

    return (
        <>
            <Head title={`Evento #${event.id}`} />
            <div className="flex flex-col gap-4 p-5">
                {/* Header */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="mb-1 flex flex-wrap items-center gap-1.5">
                            {event.severityLabel && (
                                <span
                                    className="rounded px-1.5 py-0.5 text-3xs font-semibold uppercase"
                                    style={{
                                        color: event.severityColor ?? undefined,
                                        backgroundColor: event.severityColor
                                            ? `${event.severityColor}22`
                                            : undefined,
                                    }}
                                >
                                    {event.severityLabel}
                                </span>
                            )}
                            <Badge
                                variant="outline"
                                className={
                                    unmapped
                                        ? 'text-severity-high'
                                        : 'text-fg-3'
                                }
                            >
                                {event.status ?? '—'}
                            </Badge>
                            {event.category && (
                                <Badge variant="outline" className="text-fg-3">
                                    {event.category}
                                </Badge>
                            )}
                        </div>
                        <h1 className="text-lg font-semibold text-fg-1">
                            {event.eventType ??
                                event.eventTypeCode ??
                                `Evento #${event.id}`}
                        </h1>
                        <p className="text-xs text-fg-3">
                            {event.occurredAt
                                ? new Date(event.occurredAt).toLocaleString(
                                      'es',
                                  )
                                : '—'}{' '}
                            · {event.asset ?? 'sin activo'} ·{' '}
                            {event.driver ?? 'sin conductor'} ·{' '}
                            {event.provider ?? '—'}
                        </p>
                    </div>
                    <Button size="sm" variant="outline" asChild>
                        <Link href={teamSlug ? `/${teamSlug}/events` : '#'}>
                            <ArrowLeft size={12} />
                            Volver a eventos
                        </Link>
                    </Button>
                </div>

                {unmapped && (
                    <div className="rounded-md border border-severity-high/40 bg-severity-high/10 px-3 py-2 text-xs text-fg-2">
                        Este evento no coincidió con ninguna regla de mapeo —
                        revisa el payload crudo y añade la regla en
                        Normalización.
                    </div>
                )}

                {/* Pipeline cards */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm uppercase">
                                Evaluación IA
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-xs text-fg-2">
                            {evaluation === null ? (
                                <p className="text-fg-3">Sin evaluación.</p>
                            ) : (
                                <ul className="flex flex-col gap-1">
                                    <li>
                                        Clasificación:{' '}
                                        <strong className="text-fg-1">
                                            {evaluation.classification ?? '—'}
                                        </strong>{' '}
                                        (v{evaluation.version} ·{' '}
                                        {evaluation.mode ?? '—'})
                                    </li>
                                    <li>
                                        Confianza:{' '}
                                        {evaluation.confidenceScore !== null
                                            ? `${Math.round(evaluation.confidenceScore * 100)}%`
                                            : '—'}
                                    </li>
                                    <li>
                                        Riesgo:{' '}
                                        {evaluation.riskScore !== null
                                            ? evaluation.riskScore.toFixed(2)
                                            : '—'}{' '}
                                        · Prioridad:{' '}
                                        {evaluation.priorityLevel ?? '—'}
                                    </li>
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm uppercase">
                                Decisión
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-xs text-fg-2">
                            {decision === null ? (
                                <p className="text-fg-3">Sin decisión.</p>
                            ) : (
                                <ul className="flex flex-col gap-1">
                                    <li>
                                        Resultado:{' '}
                                        <strong className="text-fg-1">
                                            {decision.code ?? '—'}
                                        </strong>
                                        {decision.requiresHumanReview && (
                                            <Badge
                                                variant="outline"
                                                className="ml-1.5 text-severity-high"
                                            >
                                                revisión humana
                                            </Badge>
                                        )}
                                    </li>
                                    <li className="text-fg-3">
                                        {decision.reason ?? ''}
                                    </li>
                                    <li className="font-mono text-2xs text-fg-3">
                                        {decision.decidedAt
                                            ? new Date(
                                                  decision.decidedAt,
                                              ).toLocaleString('es')
                                            : ''}
                                    </li>
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm uppercase">
                                Incidente
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-xs text-fg-2">
                            {incident === null ? (
                                <p className="text-fg-3">
                                    No generó incidente.
                                </p>
                            ) : (
                                <div className="flex flex-col gap-1">
                                    {teamSlug ? (
                                        <Link
                                            href={`/${teamSlug}/incidents/${incident.id}`}
                                            className="font-medium text-fg-1 hover:underline"
                                        >
                                            {incident.title}
                                        </Link>
                                    ) : (
                                        <span className="font-medium text-fg-1">
                                            {incident.title}
                                        </span>
                                    )}
                                    <span className="text-fg-3">
                                        {incident.status ?? '—'} ·{' '}
                                        {incident.severity ?? '—'}
                                    </span>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Media */}
                {media.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm uppercase">
                                Media ({media.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-wrap gap-2">
                                {media.map((item) => (
                                    <a
                                        key={item.id}
                                        href={item.url ?? '#'}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="flex h-20 w-32 items-center justify-center overflow-hidden rounded-md border border-border bg-surface-2 text-2xs text-fg-3"
                                    >
                                        {item.thumbnailUrl ? (
                                            <img
                                                src={item.thumbnailUrl}
                                                alt={`Media #${item.id}`}
                                                className="h-full w-full object-cover"
                                            />
                                        ) : (
                                            (item.mediaType ?? 'media')
                                        )}
                                    </a>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Payloads */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <JsonBlock
                        title="Payload normalizado"
                        data={event.payload}
                    />
                    <JsonBlock title="Contexto" data={event.context} />
                </div>
                <JsonBlock
                    title={`Payload crudo${event.rawEventId !== null ? ` (raw event #${event.rawEventId})` : ''}`}
                    data={event.rawPayload}
                />
            </div>
        </>
    );
}

EventShow.layout = (props: {
    currentTeam?: { slug: string } | null;
    event?: { id: number } | null;
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
                      title: `#${props.event.id}`,
                      href:
                          props.currentTeam && props.event
                              ? `/${props.currentTeam.slug}/events/${props.event.id}`
                              : '#',
                  },
              ]
            : []),
    ],
});
