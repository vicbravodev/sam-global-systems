import { Link } from '@inertiajs/react';
import { ChevronRight, ShieldAlert } from 'lucide-react';

import { toSeverity } from '@/components/sam/event-severity';
import { RelativeTime } from '@/components/sam/relative-time';
import { SeverityBadge } from '@/components/sam/severity-badge';
import { StatusPill } from '@/components/sam/status-pill';
import type { IncidentStatus } from '@/components/sam/status-pill';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { minutesSince } from '@/lib/time';
import incidentRoutes from '@/routes/incidents';

export interface LinkedIncidentEntry {
    id: number;
    /** Per-tenant display reference (INC-00036). */
    reference?: string;
    title: string;
    status: { code: string; name: string; uiStatus?: string } | null;
    priority: { code: string; name: string } | null;
    type: string | null;
    openedAt: string | null;
}

interface Props {
    incidents: LinkedIncidentEntry[];
    teamSlug: string | null;
    /** Copy for the empty state, e.g. "Esta unidad". */
    subject: string;
    className?: string;
}

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

/** Incidents linked to a driver or a unit, same pills as the inbox. */
export function LinkedIncidentsCard({
    incidents,
    teamSlug,
    subject,
    className,
}: Props) {
    return (
        <Card className={className ?? 'gap-0 overflow-hidden py-0'}>
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <ShieldAlert size={15} /> Incidentes
                </CardTitle>
                {teamSlug && incidents.length > 0 && (
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={incidentRoutes.index(teamSlug)}>
                            Ver bandeja
                            <ChevronRight />
                        </Link>
                    </Button>
                )}
            </CardHeader>
            <CardContent className="p-0">
                {incidents.length === 0 ? (
                    <p className="px-4 py-6 text-sm text-fg-3">
                        {subject} no tiene incidentes registrados.
                    </p>
                ) : (
                    <ul className="divide-y divide-border">
                        {incidents.map((incident) => (
                            <li key={incident.id}>
                                <Link
                                    href={
                                        teamSlug
                                            ? incidentRoutes.show([
                                                  teamSlug,
                                                  incident.id,
                                              ])
                                            : '#'
                                    }
                                    className="flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-surface-2"
                                >
                                    <SeverityBadge
                                        level={toSeverity(
                                            incident.priority?.code,
                                        )}
                                    />
                                    <span className="flex min-w-0 flex-1 flex-col">
                                        <span className="truncate text-sm text-fg-1">
                                            {incident.title}
                                        </span>
                                        {(incident.reference ||
                                            incident.type) && (
                                            <span className="truncate text-2xs text-fg-3">
                                                {incident.reference && (
                                                    <span className="font-mono">
                                                        {incident.reference}
                                                    </span>
                                                )}
                                                {incident.reference &&
                                                    incident.type &&
                                                    ' · '}
                                                {incident.type}
                                            </span>
                                        )}
                                    </span>
                                    {incident.status && (
                                        <StatusPill
                                            state={asUiStatus(
                                                incident.status.uiStatus,
                                            )}
                                            label={incident.status.name}
                                        />
                                    )}
                                    {incident.openedAt && (
                                        <RelativeTime
                                            minutes={minutesSince(
                                                incident.openedAt,
                                            )}
                                        />
                                    )}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
