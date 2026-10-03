import { Link } from '@inertiajs/react';
import { ExternalLink, Truck, User } from 'lucide-react';
import { DetailHeader } from '@/components/sam/detail-header';
import { toSeverity } from '@/components/sam/event-severity';
import { EventCategoryIcon } from '@/components/sam/events/event-category-icon';
import { PipelineStatusPill } from '@/components/sam/events/pipeline-status';
import { ProviderTag } from '@/components/sam/provider-tag';
import { RelativeTime } from '@/components/sam/relative-time';
import { SeverityBadge } from '@/components/sam/severity-badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import { providerDescriptionLabel } from '@/lib/labels';
import { minutesSince } from '@/lib/time';
import assetRoutes from '@/routes/assets';
import driverRoutes from '@/routes/drivers';
import eventRoutes from '@/routes/events';
import type { EventDetail } from '@/types/events';

export function EventHero({
    event,
    teamSlug,
}: {
    event: EventDetail;
    teamSlug: string | null;
}) {
    const severity = toSeverity(event.severity);

    return (
        <DetailHeader
            backHref={teamSlug ? eventRoutes.index.url(teamSlug) : '#'}
            backLabel="Volver a eventos"
            media={
                <span className="grid size-12 shrink-0 place-items-center rounded-md border border-border bg-surface-2">
                    <EventCategoryIcon code={event.categoryCode} size={22} />
                </span>
            }
            eyebrow={
                <>
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
                </>
            }
            title={
                event.eventType ?? event.eventTypeCode ?? `Evento #${event.id}`
            }
            subtitle={providerDescriptionLabel(
                event.description,
                event.eventType,
            )}
            meta={
                <>
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
                                    ? assetRoutes.show([
                                          teamSlug,
                                          event.assetId,
                                      ])
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
                                    ? driverRoutes.show([
                                          teamSlug,
                                          event.driverId,
                                      ])
                                    : '#'
                            }
                            className="inline-flex items-center gap-1 text-fg-2 hover:text-primary hover:underline"
                        >
                            <User size={12} strokeWidth={1.75} />
                            {event.driver}
                        </Link>
                    )}
                    {event.provider && <ProviderTag name={event.provider} />}
                    <span className="font-mono text-3xs">#{event.id}</span>
                </>
            }
            actions={
                event.facts.externalUrl && (
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
                )
            }
        />
    );
}
