import { Link } from '@inertiajs/react';
import { Activity, ChevronRight } from 'lucide-react';

import { toSeverity } from '@/components/sam/event-severity';
import { RelativeTime } from '@/components/sam/relative-time';
import { SeverityBadge } from '@/components/sam/severity-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import { minutesSince } from '@/lib/time';
import assetRoutes from '@/routes/assets';
import driverRoutes from '@/routes/drivers';
import eventRoutes from '@/routes/events';

export interface RecentEventEntry {
    id: number;
    occurredAt: string | null;
    eventType: string | null;
    category: string | null;
    severity: string | null;
    /** Counterpart of the page subject: the unit on a driver page, the driver on a unit page. */
    asset?: { id: number; name: string } | null;
    driver?: { id: number; name: string } | null;
}

interface Props {
    events: RecentEventEntry[];
    teamSlug: string | null;
    /** Copy for the empty state, e.g. "Este conductor…". */
    subject: string;
    className?: string;
}

/**
 * Compact timeline of the latest normalized events of a driver or a unit,
 * each row linking to the event detail and to its counterpart.
 */
export function RecentEventsCard({
    events,
    teamSlug,
    subject,
    className,
}: Props) {
    return (
        <Card className={className ?? 'gap-0 overflow-hidden py-0'}>
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <Activity size={15} /> Actividad reciente
                </CardTitle>
                {teamSlug && events.length > 0 && (
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={eventRoutes.index(teamSlug)}>
                            Todos los eventos
                            <ChevronRight />
                        </Link>
                    </Button>
                )}
            </CardHeader>
            <CardContent className="p-0">
                {events.length === 0 ? (
                    <p className="px-4 py-6 text-sm text-fg-3">
                        {subject} no ha generado eventos todavía. Aquí verás
                        frenadas bruscas, alertas de fatiga, pánico y demás
                        señales en cuanto lleguen del proveedor.
                    </p>
                ) : (
                    <ol className="divide-y divide-border">
                        {events.map((event) => {
                            const counterpart = event.asset ?? event.driver;
                            const counterpartHref =
                                teamSlug && counterpart
                                    ? event.asset
                                        ? assetRoutes.show.url([
                                              teamSlug,
                                              event.asset.id,
                                          ])
                                        : driverRoutes.show.url([
                                              teamSlug,
                                              event.driver!.id,
                                          ])
                                    : null;

                            return (
                                <li
                                    key={event.id}
                                    className="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-surface-2"
                                >
                                    <SeverityBadge
                                        level={toSeverity(event.severity)}
                                    />
                                    <div className="flex min-w-0 flex-1 flex-col">
                                        <Link
                                            prefetch={teamSlug !== null}
                                            href={
                                                teamSlug
                                                    ? eventRoutes.show([
                                                          teamSlug,
                                                          event.id,
                                                      ])
                                                    : '#'
                                            }
                                            className="truncate text-sm text-fg-1 hover:text-primary hover:underline"
                                        >
                                            {event.eventType ??
                                                `Evento #${event.id}`}
                                        </Link>
                                        <span className="truncate text-2xs text-fg-3">
                                            {event.category ?? 'Sin categoría'}
                                            {counterpart && (
                                                <>
                                                    {' · '}
                                                    {counterpartHref ? (
                                                        <Link
                                                            href={
                                                                counterpartHref
                                                            }
                                                            className="hover:text-primary hover:underline"
                                                        >
                                                            {counterpart.name}
                                                        </Link>
                                                    ) : (
                                                        counterpart.name
                                                    )}
                                                </>
                                            )}
                                        </span>
                                    </div>
                                    {event.occurredAt && (
                                        <span
                                            className="shrink-0 text-right"
                                            title={formatDateTime(
                                                event.occurredAt,
                                            )}
                                        >
                                            <RelativeTime
                                                minutes={minutesSince(
                                                    event.occurredAt,
                                                )}
                                            />
                                        </span>
                                    )}
                                </li>
                            );
                        })}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}
