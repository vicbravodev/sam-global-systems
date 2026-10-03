import { MetaChip, ProviderTag, RealtimeStatus } from '@/components/sam';
import { SEVERITY_TONE, toSeverity } from '@/components/sam/event-severity';
import type { RealtimeState } from '@/components/sam/realtime-status';
import { StatusBadge } from '@/components/sam/status-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useRealtimeConnection } from '@/hooks/use-realtime-connection';
import { formatClock } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { DashboardStreamEvent } from '@/types/dashboard';

function connectionToStatus(
    state: ReturnType<typeof useRealtimeConnection>,
): RealtimeState {
    switch (state) {
        case 'connected':
            return 'ok';
        case 'connecting':
        case 'reconnecting':
            return 'warn';
        default:
            return 'down';
    }
}

export function LiveStreamPanel({
    events,
}: {
    events: DashboardStreamEvent[];
}) {
    const connection = useRealtimeConnection();

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0">Stream en vivo</CardTitle>
                <RealtimeStatus state={connectionToStatus(connection)} />
            </CardHeader>
            <CardContent className="p-0">
                {events.length === 0 ? (
                    <p className="px-4 py-6 text-sm text-fg-3">
                        Aún no hay eventos normalizados.
                    </p>
                ) : (
                    <ul className="max-h-72 overflow-auto py-1">
                        {events.map((event, index) => (
                            <li
                                key={event.id}
                                className={cn(
                                    'flex items-center gap-2 px-4 py-1.5',
                                    index === 0 && 'sam-flash',
                                )}
                            >
                                <span className="w-14 shrink-0 font-mono text-2xs text-fg-3 tabular-nums">
                                    {formatClock(event.occurredAt)}
                                </span>
                                <ProviderTag name={event.provider} />
                                <span className="flex-1 truncate text-xs text-fg-2">
                                    {event.type} ·{' '}
                                    <span className="text-fg-1">
                                        {event.asset}
                                    </span>
                                </span>
                                <DecisionChip
                                    decision={event.decision}
                                    severity={event.severity}
                                />
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function DecisionChip({
    decision,
    severity,
}: {
    decision: DashboardStreamEvent['decision'];
    severity: DashboardStreamEvent['severity'];
}) {
    const isAlert = decision === 'incident' || decision === 'escalate';
    const labels: Record<DashboardStreamEvent['decision'], string> = {
        incident: 'Incidente',
        escalate: 'Escalado',
        info: 'Info',
        discard: 'Descartado',
    };

    if (!isAlert) {
        return <MetaChip>{labels[decision]}</MetaChip>;
    }

    return (
        <StatusBadge
            tone={SEVERITY_TONE[toSeverity(severity ?? 'high')]}
            label={labels[decision]}
        />
    );
}
