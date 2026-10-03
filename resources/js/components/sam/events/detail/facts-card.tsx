import { MapPin, Tag } from 'lucide-react';
import {
    DescriptionItem,
    DescriptionList,
} from '@/components/sam/description-list';
import { toSeverity } from '@/components/sam/event-severity';
import { PointMap } from '@/components/sam/lazy-point-map';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import type { EventDetail } from '@/types/events';

const EVENT_STATE_LABELS: Record<string, string> = {
    needsReview: 'Pendiente de revisión en el proveedor',
    reviewed: 'Revisado en el proveedor',
    dismissed: 'Descartado en el proveedor',
    needsCoaching: 'Requiere coaching',
    coached: 'Coaching realizado',
};

export function FactsCard({ event }: { event: EventDetail }) {
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
                            variant="pin"
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
                <DescriptionList className="p-4">
                    <DescriptionItem label="Cuándo">
                        {formatDateTime(event.occurredAt)}
                    </DescriptionItem>
                    <DescriptionItem label="Procesado">
                        {event.processedAt
                            ? formatDateTime(event.processedAt)
                            : '—'}
                    </DescriptionItem>
                    {facts.location && (
                        <DescriptionItem label="Dónde">
                            {facts.location.formatted ?? 'Sin geocodificar'}
                            <span className="ml-2 font-mono text-2xs text-fg-3 tabular-nums">
                                {facts.location.latitude.toFixed(5)},{' '}
                                {facts.location.longitude.toFixed(5)}
                            </span>
                        </DescriptionItem>
                    )}
                    {facts.labels.length > 0 && (
                        <DescriptionItem label="Etiquetas del proveedor">
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
                        </DescriptionItem>
                    )}
                    {facts.externalEventType && (
                        <DescriptionItem label="Tipo en el proveedor">
                            <span className="font-mono text-xs">
                                {facts.externalEventType}
                            </span>
                        </DescriptionItem>
                    )}
                    {(facts.isResolved !== null || facts.eventState) && (
                        <DescriptionItem label="Estado en el proveedor">
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
                        </DescriptionItem>
                    )}
                </DescriptionList>
            </CardContent>
        </Card>
    );
}
