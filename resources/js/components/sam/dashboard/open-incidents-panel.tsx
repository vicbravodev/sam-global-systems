import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { SeverityBadge, SlaCountdown } from '@/components/sam';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import incidentRoutes from '@/routes/incidents';
import type { IncidentRow } from '@/types/dashboard';

export function OpenIncidentsPanel({
    incidents,
    teamSlug,
}: {
    incidents: IncidentRow[];
    teamSlug: string | null;
}) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0">
                    Incidentes abiertos
                </CardTitle>
                {teamSlug ? (
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={incidentRoutes.index(teamSlug)}>
                            Ver todos
                            <ChevronRight />
                        </Link>
                    </Button>
                ) : null}
            </CardHeader>
            <CardContent className="p-0">
                {incidents.length === 0 ? (
                    <p className="px-4 py-6 text-sm text-fg-3">
                        Sin incidentes abiertos ahora mismo.
                    </p>
                ) : (
                    <ul className="divide-y divide-border">
                        {incidents.map((incident) => (
                            <li key={incident.id}>
                                <Link
                                    prefetch={teamSlug !== null}
                                    href={
                                        teamSlug
                                            ? incidentRoutes.show([
                                                  teamSlug,
                                                  incident.incidentId,
                                              ])
                                            : '#'
                                    }
                                    className="flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors hover:bg-surface-2"
                                >
                                    <SeverityBadge level={incident.severity} />
                                    <span className="w-16 shrink-0 font-mono text-2xs text-fg-3 tabular-nums">
                                        {incident.id}
                                    </span>
                                    <span className="flex-1 truncate text-sm">
                                        {incident.title}
                                    </span>
                                    <SlaCountdown
                                        seconds={incident.slaSeconds}
                                        total={incident.slaTotal}
                                    />
                                    <ChevronRight className="size-4 text-fg-3" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
