import { Link } from '@inertiajs/react';
import { History } from 'lucide-react';
import { SeverityBadge } from '@/components/sam';
import type { Severity } from '@/components/sam/severity-badge';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/format';
import type { PriorIncidentSummary } from '@/types/sam';

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

interface PriorIncidentsProps {
    priorIncidents: PriorIncidentSummary[];
    teamSlug: string | null;
}

export function PriorIncidents({
    priorIncidents,
    teamSlug,
}: PriorIncidentsProps) {
    return (
        <section>
            <h3 className="mb-2 flex items-center gap-1.5 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                <History size={11} strokeWidth={1.5} />
                Historial relacionado
                {priorIncidents.length > 0 && (
                    <span className="font-mono text-fg-3 normal-case">
                        {priorIncidents.length}
                    </span>
                )}
            </h3>
            {priorIncidents.length === 0 ? (
                <p className="text-xs text-fg-3">
                    Sin incidentes relacionados.
                </p>
            ) : (
                <ul className="m-0 flex list-none flex-col gap-1.5 p-0">
                    {priorIncidents.map((prior) => (
                        <li
                            key={prior.incidentId}
                            className="rounded-md border border-border bg-surface-1 p-2.5"
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
                                {prior.openedAt
                                    ? formatDateTime(prior.openedAt)
                                    : null}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
