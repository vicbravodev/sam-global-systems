import type { DataTableColumn } from '@/components/sam/data-table';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/format';
import type { AuditLogRow, DomainEventRow } from './types';

export const AUDIT_LOG_COLUMNS: DataTableColumn<AuditLogRow>[] = [
    {
        key: 'occurredAt',
        header: 'Cuándo',
        sortValue: (log) =>
            log.occurredAt ? Date.parse(log.occurredAt) : null,
        cell: (log) => (
            <span className="font-mono text-2xs whitespace-nowrap text-fg-2">
                {formatDateTime(log.occurredAt)}
            </span>
        ),
    },
    {
        key: 'action',
        header: 'Acción',
        sortValue: (log) => log.action,
        cell: (log) => (
            <span className="text-xs text-fg-1" title={log.action}>
                {log.actionLabel}
            </span>
        ),
    },
    {
        key: 'category',
        header: 'Categoría',
        sortValue: (log) => log.category,
        cell: (log) => (
            <Badge variant="outline" className="text-3xs text-fg-3">
                {log.categoryLabel ?? '—'}
            </Badge>
        ),
    },
    {
        key: 'actor',
        header: 'Actor',
        sortValue: (log) => log.actorLabel,
        cell: (log) => (
            <span className="text-xs text-fg-2">{log.actorLabel ?? '—'}</span>
        ),
    },
    {
        key: 'entity',
        header: 'Entidad',
        sortValue: (log) => log.entityLabel,
        cell: (log) => (
            <span className="text-xs text-fg-2">
                {log.entityLabel ?? '—'}
                {log.entityId !== null && ` #${log.entityId}`}
            </span>
        ),
    },
];

export const DOMAIN_EVENT_COLUMNS: DataTableColumn<DomainEventRow>[] = [
    {
        key: 'occurredAt',
        header: 'Cuándo',
        sortValue: (event) =>
            event.occurredAt ? Date.parse(event.occurredAt) : null,
        cell: (event) => (
            <span className="font-mono text-2xs whitespace-nowrap text-fg-2">
                {formatDateTime(event.occurredAt)}
            </span>
        ),
    },
    {
        key: 'event',
        header: 'Evento',
        sortValue: (event) => event.eventName,
        cell: (event) => (
            <span className="font-mono text-2xs text-fg-1">
                {event.eventName}
            </span>
        ),
    },
    {
        key: 'aggregate',
        header: 'Agregado',
        sortValue: (event) => event.aggregateType,
        cell: (event) => (
            <span className="font-mono text-2xs text-fg-2">
                {event.aggregateType ?? '—'}
                {event.aggregateId !== null && ` #${event.aggregateId}`}
            </span>
        ),
    },
    {
        key: 'correlation',
        header: 'Correlación',
        cell: (event) => (
            <span className="font-mono text-2xs text-fg-2">
                {event.correlationId ?? '—'}
            </span>
        ),
    },
];
