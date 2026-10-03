import { Link } from '@inertiajs/react';
import { ChevronRight, ShieldAlert } from 'lucide-react';
import { toSeverity } from '@/components/sam/event-severity';
import { SeverityBadge } from '@/components/sam/severity-badge';
import { StatusPill } from '@/components/sam/status-pill';
import type { IncidentStatus } from '@/components/sam/status-pill';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import incidentRoutes from '@/routes/incidents';
import type { EventIncident } from '@/types/events';

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

export function IncidentCard({
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
                                ? incidentRoutes.show([teamSlug, incident.id])
                                : '#'
                        }
                        className="flex items-center gap-3 rounded-md border border-border bg-surface-2 p-3 transition-colors hover:border-primary/40"
                    >
                        <SeverityBadge level={toSeverity(incident.severity)} />
                        <span className="flex min-w-0 flex-1 flex-col">
                            <span className="truncate text-sm font-medium text-fg-1">
                                <span className="font-mono text-fg-3">
                                    {incident.reference}
                                </span>{' '}
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
