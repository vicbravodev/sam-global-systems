import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    ChevronRight,
    Copy,
    ExternalLink,
    Film,
    Image as ImageIcon,
    MapPin,
    Mic,
    ShieldAlert,
    Sparkles,
    Tag,
    Truck,
    User,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { toast } from 'sonner';
import { ConfidenceBar } from '@/components/sam/confidence-bar';
import { toSeverity } from '@/components/sam/event-severity';
import { EventCategoryIcon } from '@/components/sam/events/event-category-icon';
import { PipelineStatusPill } from '@/components/sam/events/pipeline-status';
import { PointMap } from '@/components/sam/point-map';
import { ProviderTag } from '@/components/sam/provider-tag';
import { RelativeTime } from '@/components/sam/relative-time';
import { SeverityBadge } from '@/components/sam/severity-badge';
import { StatusPill } from '@/components/sam/status-pill';
import type { IncidentStatus } from '@/components/sam/status-pill';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import {
    actionLabel,
    mediaRoleLabel,
    providerDescriptionLabel,
} from '@/lib/labels';
import { minutesSince } from '@/lib/time';
import { cn } from '@/lib/utils';
import type {
    EventDecision,
    EventDetail,
    EventEvaluation,
    EventIncident,
    EventMediaItem,
    EventShowProps,
} from '@/types/events';

const MODE_LABELS: Record<string, string> = {
    rules_only: 'Solo reglas',
    ai_text: 'IA (texto)',
    multimodal: 'IA multimodal',
    hybrid: 'Híbrido',
    deferred_pending_media: 'Diferido, esperando media',
};

const PRIORITY_LABELS: Record<string, string> = {
    low: 'Baja',
    normal: 'Normal',
    high: 'Alta',
    urgent: 'Urgente',
    medium: 'Media',
    critical: 'Crítica',
};

const ACTION_LABELS: Record<string, string> = {
    escalate_to_operator: 'Escalar a un operador',
    call_driver: 'Llamar al conductor',
    ignore_event: 'Ignorar el evento',
};

const EVENT_STATE_LABELS: Record<string, string> = {
    needsReview: 'Pendiente de revisión en el proveedor',
    reviewed: 'Revisado en el proveedor',
    dismissed: 'Descartado en el proveedor',
    needsCoaching: 'Requiere coaching',
    coached: 'Coaching realizado',
};

const MEDIA_ICONS: Record<string, LucideIcon> = {
    image: ImageIcon,
    snapshot: ImageIcon,
    video: Film,
    clip: Film,
    audio: Mic,
};

const MEDIA_LABELS: Record<string, string> = {
    image: 'Imagen',
    snapshot: 'Captura',
    video: 'Video',
    clip: 'Clip',
    audio: 'Audio',
};

function asUiStatus(value: string | undefined): IncidentStatus {
    const known: IncidentStatus[] = [
        'new',
        'triaging',
        'assigned',
        'escalated',
        'in-progress',
        'resolved',
        'closed',
        'discarded',
    ];

    return known.includes(value as IncidentStatus)
        ? (value as IncidentStatus)
        : 'new';
}

// ---- Header ----

function EventHero({
    event,
    teamSlug,
}: {
    event: EventDetail;
    teamSlug: string | null;
}) {
    const severity = toSeverity(event.severity);

    return (
        <header className="flex flex-wrap items-start justify-between gap-4">
            <div className="flex min-w-0 items-start gap-3">
                <Button variant="ghost" size="sm" asChild className="mt-1">
                    <Link
                        href={teamSlug ? `/${teamSlug}/events` : '#'}
                        aria-label="Volver a eventos"
                    >
                        <ArrowLeft size={15} />
                    </Link>
                </Button>
                <span className="grid size-12 shrink-0 place-items-center rounded-md border border-border bg-surface-2">
                    <EventCategoryIcon code={event.categoryCode} size={22} />
                </span>
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        {event.severity && <SeverityBadge level={severity} />}
                        <PipelineStatusPill
                            status={event.status}
                            label={event.statusLabel}
                        />
                        {event.category && (
                            <span className="text-2xs text-fg-3">
                                {event.category}
                            </span>
                        )}
                    </div>
                    <h1 className="sam-h1 mt-1 truncate">
                        {event.eventType ??
                            event.eventTypeCode ??
                            `Evento #${event.id}`}
                    </h1>
                    {providerDescriptionLabel(
                        event.description,
                        event.eventType,
                    ) && (
                        <p className="mt-0.5 text-sm text-fg-2">
                            {providerDescriptionLabel(
                                event.description,
                                event.eventType,
                            )}
                        </p>
                    )}
                    <p className="sam-meta mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5">
                        {event.occurredAt && (
                            <span title={event.occurredAt}>
                                {formatDateTime(event.occurredAt)} ·{' '}
                                <RelativeTime
                                    minutes={minutesSince(event.occurredAt)}
                                />
                            </span>
                        )}
                        {event.asset && (
                            <Link
                                href={
                                    teamSlug && event.assetId !== null
                                        ? `/${teamSlug}/assets/${event.assetId}`
                                        : '#'
                                }
                                className="inline-flex items-center gap-1 text-fg-2 hover:text-primary hover:underline"
                            >
                                <Truck size={12} strokeWidth={1.75} />
                                {event.asset}
                            </Link>
                        )}
                        {event.driver && (
                            <Link
                                href={
                                    teamSlug && event.driverId !== null
                                        ? `/${teamSlug}/drivers/${event.driverId}`
                                        : '#'
                                }
                                className="inline-flex items-center gap-1 text-fg-2 hover:text-primary hover:underline"
                            >
                                <User size={12} strokeWidth={1.75} />
                                {event.driver}
                            </Link>
                        )}
                        {event.provider && (
                            <ProviderTag name={event.provider} />
                        )}
                        <span className="font-mono text-3xs">#{event.id}</span>
                    </p>
                </div>
            </div>
            {event.facts.externalUrl && (
                <Button variant="outline" size="sm" asChild>
                    <a
                        href={event.facts.externalUrl}
                        target="_blank"
                        rel="noreferrer"
                    >
                        <ExternalLink size={13} />
                        Ver en {event.provider ?? 'el proveedor'}
                    </a>
                </Button>
            )}
        </header>
    );
}

// ---- Pipeline stepper ----

type StepState = 'done' | 'active' | 'pending' | 'failed' | 'skipped';

function PipelineStepper({
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
            state: evaluation
                ? 'done'
                : failed || unmapped
                  ? 'skipped'
                  : 'pending',
            note: evaluation?.classificationLabel ?? null,
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

// ---- Facts ----

function FactRow({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-2xs tracking-caps text-fg-3 uppercase">
                {label}
            </dt>
            <dd className="text-sm text-fg-1">{children}</dd>
        </div>
    );
}

function FactsCard({ event }: { event: EventDetail }) {
    const facts = event.facts;
    const severity = toSeverity(event.severity);

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <MapPin size={15} /> Qué pasó y dónde
                </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
                {facts.location && (
                    <div className="h-56 border-b border-border">
                        <PointMap
                            latitude={facts.location.latitude}
                            longitude={facts.location.longitude}
                            label={event.eventType ?? undefined}
                            tone={
                                severity === 'critical'
                                    ? 'critical'
                                    : severity === 'high'
                                      ? 'high'
                                      : severity === 'medium'
                                        ? 'warn'
                                        : 'primary'
                            }
                            zoom={14}
                        />
                    </div>
                )}
                <dl className="grid grid-cols-2 gap-x-4 gap-y-3 p-4">
                    <FactRow label="Cuándo">
                        {formatDateTime(event.occurredAt)}
                    </FactRow>
                    <FactRow label="Procesado">
                        {event.processedAt
                            ? formatDateTime(event.processedAt)
                            : '—'}
                    </FactRow>
                    {facts.location && (
                        <FactRow label="Dónde">
                            {facts.location.formatted ?? 'Sin geocodificar'}
                            <span className="ml-2 font-mono text-2xs text-fg-3 tabular-nums">
                                {facts.location.latitude.toFixed(5)},{' '}
                                {facts.location.longitude.toFixed(5)}
                            </span>
                        </FactRow>
                    )}
                    {facts.labels.length > 0 && (
                        <FactRow label="Etiquetas del proveedor">
                            <span className="flex flex-wrap gap-1">
                                {facts.labels.map((label) => (
                                    <span
                                        key={label}
                                        className="inline-flex items-center gap-1 rounded-sm border border-border bg-surface-2 px-1.5 py-0.5 text-2xs text-fg-2"
                                    >
                                        <Tag size={10} aria-hidden="true" />
                                        {label}
                                    </span>
                                ))}
                            </span>
                        </FactRow>
                    )}
                    {facts.externalEventType && (
                        <FactRow label="Tipo en el proveedor">
                            <span className="font-mono text-xs">
                                {facts.externalEventType}
                            </span>
                        </FactRow>
                    )}
                    {(facts.isResolved !== null || facts.eventState) && (
                        <FactRow label="Estado en el proveedor">
                            {facts.isResolved === true ? (
                                <span className="text-severity-low">
                                    Resuelto en origen
                                    {facts.externalResolvedAt &&
                                        ` · ${formatDateTime(facts.externalResolvedAt)}`}
                                </span>
                            ) : facts.eventState ? (
                                (EVENT_STATE_LABELS[facts.eventState] ??
                                facts.eventState)
                            ) : (
                                'Abierto en origen'
                            )}
                        </FactRow>
                    )}
                </dl>
            </CardContent>
        </Card>
    );
}

// ---- AI / decision / incident ----

function EvaluationCard({
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
                                        {PRIORITY_LABELS[
                                            evaluation.priorityLevel
                                        ] ?? evaluation.priorityLevel}
                                    </span>
                                </span>
                            )}
                            {evaluation.requiresAction && (
                                <span className="rounded-sm border border-severity-high/40 bg-severity-high/10 px-1.5 py-0.5 text-3xs font-semibold text-severity-high">
                                    Requiere acción
                                </span>
                            )}
                        </div>
                        <dl className="grid grid-cols-2 gap-3">
                            <div>
                                <dt className="text-2xs tracking-caps text-fg-3 uppercase">
                                    Confianza
                                </dt>
                                <dd className="mt-1">
                                    {evaluation.confidenceScore !== null ? (
                                        <ConfidenceBar
                                            value={evaluation.confidenceScore}
                                        />
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-2xs tracking-caps text-fg-3 uppercase">
                                    Riesgo estimado
                                </dt>
                                <dd className="mt-1 font-mono text-sm text-fg-1 tabular-nums">
                                    {evaluation.riskScore !== null
                                        ? `${Math.round(evaluation.riskScore * 100)} / 100`
                                        : '—'}
                                </dd>
                            </div>
                            {evaluation.recommendedAction && (
                                <div className="col-span-2">
                                    <dt className="text-2xs tracking-caps text-fg-3 uppercase">
                                        Acción recomendada
                                    </dt>
                                    <dd className="mt-0.5 text-sm text-fg-1">
                                        {ACTION_LABELS[
                                            evaluation.recommendedAction
                                        ] ??
                                            actionLabel(
                                                evaluation.recommendedAction,
                                            )}
                                    </dd>
                                </div>
                            )}
                        </dl>
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

function DecisionCard({ decision }: { decision: EventDecision | null }) {
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
                                    ` · prioridad ${(PRIORITY_LABELS[decision.priorityLevel] ?? decision.priorityLevel).toLowerCase()}`}
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

function IncidentCard({
    incident,
    teamSlug,
}: {
    incident: EventIncident | null;
    teamSlug: string | null;
}) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <ShieldAlert size={15} /> Incidente
                </CardTitle>
            </CardHeader>
            <CardContent className="p-4">
                {incident === null ? (
                    <p className="text-sm text-fg-3">
                        Este evento no generó incidente.
                    </p>
                ) : (
                    <Link
                        href={
                            teamSlug
                                ? `/${teamSlug}/incidents/${incident.id}`
                                : '#'
                        }
                        className="flex items-center gap-3 rounded-md border border-border bg-surface-2 p-3 transition-colors hover:border-primary/40"
                    >
                        <SeverityBadge level={toSeverity(incident.severity)} />
                        <span className="flex min-w-0 flex-1 flex-col">
                            <span className="truncate text-sm font-medium text-fg-1">
                                {incident.title}
                            </span>
                            {incident.openedAt && (
                                <span className="text-2xs text-fg-3">
                                    abierto {formatDateTime(incident.openedAt)}
                                </span>
                            )}
                        </span>
                        <StatusPill
                            state={asUiStatus(incident.uiStatus)}
                            label={incident.statusLabel}
                        />
                        <ChevronRight size={14} className="text-fg-3" />
                    </Link>
                )}
            </CardContent>
        </Card>
    );
}

// ---- Media ----

function MediaCard({ media }: { media: EventMediaItem[] }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <Film size={15} /> Evidencia
                </CardTitle>
                <span className="sam-meta">
                    {media.length} {media.length === 1 ? 'archivo' : 'archivos'}
                </span>
            </CardHeader>
            <CardContent className="p-4">
                {media.length === 0 ? (
                    <p className="text-sm text-fg-3">
                        Sin media asociada. Para eventos con cámara, SAM pide el
                        clip y las capturas alrededor del momento del evento.
                    </p>
                ) : (
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        {media.map((item) => {
                            const Icon =
                                (item.mediaType &&
                                    MEDIA_ICONS[item.mediaType]) ||
                                Film;
                            const label =
                                (item.mediaType &&
                                    MEDIA_LABELS[item.mediaType]) ||
                                'Media';

                            return (
                                <a
                                    key={item.id}
                                    href={item.url ?? '#'}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="group flex flex-col overflow-hidden rounded-md border border-border bg-surface-2 transition-colors hover:border-primary/40"
                                >
                                    <span className="relative grid aspect-video place-items-center overflow-hidden bg-surface-3 text-fg-3">
                                        {item.thumbnailUrl ? (
                                            <img
                                                src={item.thumbnailUrl}
                                                alt={`${label} #${item.id}`}
                                                className="h-full w-full object-cover transition-transform group-hover:scale-[1.02]"
                                            />
                                        ) : (
                                            <Icon size={22} strokeWidth={1.5} />
                                        )}
                                        {item.durationSeconds !== null && (
                                            <span className="absolute right-1.5 bottom-1.5 rounded-sm bg-black/70 px-1.5 py-0.5 font-mono text-3xs text-white tabular-nums">
                                                {Math.floor(
                                                    item.durationSeconds / 60,
                                                )}
                                                :
                                                {String(
                                                    item.durationSeconds % 60,
                                                ).padStart(2, '0')}
                                            </span>
                                        )}
                                    </span>
                                    <span className="flex items-center justify-between gap-2 px-2 py-1.5">
                                        <span className="text-2xs text-fg-2">
                                            {label}
                                            {item.mediaRole &&
                                                ` · ${mediaRoleLabel(item.mediaRole)}`}
                                        </span>
                                        {item.capturedAt && (
                                            <span className="font-mono text-3xs text-fg-3">
                                                {formatDateTime(
                                                    item.capturedAt,
                                                )}
                                            </span>
                                        )}
                                    </span>
                                </a>
                            );
                        })}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

// ---- JSON ----

function JsonBlock({
    title,
    data,
}: {
    title: string;
    data: Record<string, unknown> | null;
}) {
    const json =
        data !== null && Object.keys(data).length > 0
            ? JSON.stringify(data, null, 2)
            : null;

    const copy = (e: React.MouseEvent) => {
        // El botón vive dentro del <summary>: sin esto, copiar también
        // colapsa/expande el bloque.
        e.preventDefault();
        e.stopPropagation();

        if (json !== null) {
            void navigator.clipboard.writeText(json);
            toast.success('Payload copiado al portapapeles.');
        }
    };

    return (
        <Card className="gap-0 py-0">
            <CardContent className="p-0">
                {/* Colapsado por defecto (F4.3): la evaluación/decisión es lo
                    que el operador necesita; el JSON es material de soporte. */}
                <details className="group">
                    <summary className="flex cursor-pointer items-center gap-2 px-4 py-3 select-none [&::-webkit-details-marker]:hidden">
                        <ChevronRight
                            size={14}
                            className="text-fg-3 transition-transform group-open:rotate-90"
                        />
                        <span className="flex-1 text-xs font-semibold tracking-caps text-fg-2 uppercase">
                            {title}
                        </span>
                        {json !== null ? (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={copy}
                                aria-label={`Copiar ${title}`}
                            >
                                <Copy size={12} />
                                Copiar
                            </Button>
                        ) : (
                            <span className="text-2xs text-fg-3">
                                sin datos
                            </span>
                        )}
                    </summary>
                    <div className="px-4 pb-4">
                        {json === null ? (
                            <p className="text-xs text-fg-3">
                                Este evento no trae datos en esta sección.
                            </p>
                        ) : (
                            <pre className="max-h-72 overflow-auto rounded-md bg-surface-2 p-3 font-mono text-2xs leading-relaxed text-fg-2">
                                {json}
                            </pre>
                        )}
                    </div>
                </details>
            </CardContent>
        </Card>
    );
}

// ---- Page ----

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
