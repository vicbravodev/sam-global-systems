import {
    BarChart2,
    CheckCircle2,
    FileCode,
    Link as LinkIcon,
    Map,
    Video,
} from 'lucide-react';
import { SeverityBadge } from '@/components/sam';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { IncidentDetail } from '@/types/sam';

const EVIDENCE_ICON = {
    chart: BarChart2,
    video: Video,
    map: Map,
    payload: FileCode,
};

const RESOLUTION_LABEL: Record<string, string> = {
    handled_successfully: 'Resuelto correctamente',
    false_positive: 'Falso positivo',
    operator_confirmed_safe: 'Operador confirmó seguro',
    resolved_externally: 'Resuelto externamente',
    escalated_externally: 'Escalado externamente',
    unresolved_closed: 'Cerrado sin resolver',
    duplicate_incident: 'Incidente duplicado',
};

const RELATION_LABEL: Record<string, string> = {
    root_trigger: 'Disparador',
    supporting_event: 'Soporte',
};

function SectionTitle({ children }: { children: React.ReactNode }) {
    return (
        <h3 className="mb-2 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
            {children}
        </h3>
    );
}

function FactRows({
    rows,
}: {
    rows: { key: string; value: React.ReactNode }[];
}) {
    return (
        <div className="flex flex-col overflow-hidden rounded-md border border-border bg-surface-1">
            {rows.map(({ key, value }) => (
                <div
                    key={key}
                    className="grid grid-cols-[minmax(88px,auto)_minmax(0,1fr)] items-baseline gap-3 border-b border-border px-3 py-2 text-xs last:border-b-0"
                >
                    <span className="text-2xs font-medium whitespace-nowrap text-fg-3">
                        {key}
                    </span>
                    <span className="justify-self-end text-right text-fg-1">
                        {value}
                    </span>
                </div>
            ))}
        </div>
    );
}

function RiskBar({ value }: { value: number }) {
    const color =
        value > 70
            ? 'text-severity-critical'
            : value > 40
              ? 'text-severity-high'
              : 'text-severity-low';
    const bgColor =
        value > 70
            ? 'bg-severity-critical'
            : value > 40
              ? 'bg-severity-high'
              : 'bg-severity-low';

    return (
        <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
            <span className={cn('font-mono text-2xs font-semibold', color)}>
                {value}
            </span>
            <span className="h-1 w-12 shrink-0 overflow-hidden rounded-full bg-surface-3">
                <span
                    className={cn('block h-full', bgColor)}
                    style={{ width: `${value}%` }}
                />
            </span>
        </span>
    );
}

const EMPTY = (value: string | null | undefined): boolean =>
    value == null || value === '' || value === '—';

/**
 * Datos del evento origen: solo filas con valor real, nada de columnas de
 * guiones largos.
 */
export function EventFacts({ incident }: { incident: IncidentDetail }) {
    const rows: { key: string; value: React.ReactNode }[] = [];

    if (!EMPTY(incident.eventType)) {
        rows.push({
            key: 'Tipo de evento',
            value: (
                <span className="font-mono text-2xs">{incident.eventType}</span>
            ),
        });
    }

    if (incident.eventOccurredAt) {
        rows.push({
            key: 'Ocurrido',
            value: formatDateTime(incident.eventOccurredAt),
        });
    }

    if (incident.openedAt) {
        rows.push({
            key: 'Incidente abierto',
            value: formatDateTime(incident.openedAt),
        });
    }

    if (incident.slaDueAt) {
        rows.push({
            key: 'SLA vence',
            value: formatDateTime(incident.slaDueAt),
        });
    }

    if (!EMPTY(incident.provider)) {
        rows.push({ key: 'Proveedor', value: incident.provider });
    }

    if (!EMPTY(incident.location)) {
        rows.push({
            key: 'Ubicación',
            value: <span className="break-words">{incident.location}</span>,
        });
    }

    if (!EMPTY(incident.asset)) {
        rows.push({ key: 'Activo', value: incident.asset });
    }

    if (!EMPTY(incident.driver)) {
        rows.push({ key: 'Conductor', value: incident.driver });
    }

    if (rows.length === 0) {
        return null;
    }

    return (
        <section>
            <SectionTitle>Datos del evento</SectionTitle>
            <FactRows rows={rows} />
        </section>
    );
}

/**
 * Contexto operativo del snapshot del evento. Si no se capturó nada, una
 * línea honesta en lugar de cinco filas vacías.
 */
export function OperationalContext({ incident }: { incident: IncidentDetail }) {
    const ctx = incident.operationalContext;
    const rows: { key: string; value: React.ReactNode }[] = [];

    if (!EMPTY(ctx.weather)) {
        rows.push({ key: 'Clima', value: ctx.weather });
    }

    if (!EMPTY(ctx.traffic)) {
        rows.push({ key: 'Tráfico', value: ctx.traffic });
    }

    if (ctx.driverRisk > 0) {
        rows.push({
            key: 'Riesgo conductor',
            value: <RiskBar value={ctx.driverRisk} />,
        });
    }

    if (!EMPTY(ctx.geofenceStatus)) {
        rows.push({ key: 'Geocerca', value: ctx.geofenceStatus });
    }

    if (!EMPTY(ctx.drivingHours)) {
        rows.push({ key: 'Horas de conducción', value: ctx.drivingHours });
    }

    return (
        <section>
            <SectionTitle>Contexto operativo</SectionTitle>
            {rows.length === 0 ? (
                <p className="text-xs text-fg-3">
                    Sin contexto operacional capturado para este evento.
                </p>
            ) : (
                <FactRows rows={rows} />
            )}
        </section>
    );
}

/** Cómo se cerró el incidente; solo visible cuando existe resolución. */
export function ResolutionCard({ incident }: { incident: IncidentDetail }) {
    const resolution = incident.resolution;

    if (!resolution) {
        return null;
    }

    return (
        <section>
            <SectionTitle>Resolución</SectionTitle>
            <div className="rounded-md border border-health-ok/30 bg-health-ok/5 p-3">
                <div className="mb-1 flex items-center gap-1.5 text-xs font-semibold text-health-ok">
                    <CheckCircle2 size={13} strokeWidth={1.75} />
                    {resolution.code
                        ? (RESOLUTION_LABEL[resolution.code] ?? resolution.code)
                        : 'Resuelto'}
                </div>
                {resolution.summary && (
                    <p className="text-xs leading-normal text-fg-2">
                        {resolution.summary}
                    </p>
                )}
                {resolution.rootCause && (
                    <p className="mt-1 text-2xs leading-normal text-fg-3">
                        Causa raíz: {resolution.rootCause}
                    </p>
                )}
                {resolution.resolvedAt && (
                    <p className="mt-1 text-2xs text-fg-3">
                        {formatDateTime(resolution.resolvedAt)}
                    </p>
                )}
            </div>
        </section>
    );
}

/** Evidencia adjunta como lista sobria (no tiles vacíos con ícono gigante). */
export function EvidenceList({ incident }: { incident: IncidentDetail }) {
    if (incident.evidence.length === 0) {
        return null;
    }

    return (
        <section>
            <SectionTitle>Evidencia · {incident.evidence.length}</SectionTitle>
            <ul className="m-0 flex list-none flex-col gap-1.5 p-0">
                {incident.evidence.map((item, idx) => {
                    const Icon = EVIDENCE_ICON[item.type];
                    const body = (
                        <>
                            <Icon
                                size={14}
                                strokeWidth={1.5}
                                className="mt-0.5 shrink-0 text-fg-3"
                            />
                            <span className="min-w-0">
                                <span className="block text-xs font-medium text-fg-1">
                                    {item.label}
                                </span>
                                {item.sub && (
                                    <span className="mt-0.5 line-clamp-2 block text-2xs leading-normal text-fg-3">
                                        {item.sub}
                                    </span>
                                )}
                            </span>
                        </>
                    );

                    return (
                        <li key={idx}>
                            {item.fileUrl ? (
                                <a
                                    href={item.fileUrl}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="flex items-start gap-2 rounded-md border border-border bg-surface-1 p-2.5 transition-colors hover:border-fg-3"
                                >
                                    {body}
                                </a>
                            ) : (
                                <span className="flex items-start gap-2 rounded-md border border-border bg-surface-1 p-2.5">
                                    {body}
                                </span>
                            )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

/** Eventos del proveedor vinculados a este incidente. */
export function LinkedEvents({ incident }: { incident: IncidentDetail }) {
    if (incident.relatedLinks.length === 0) {
        return null;
    }

    return (
        <section>
            <SectionTitle>
                Eventos vinculados · {incident.relatedLinks.length}
            </SectionTitle>
            <ul className="m-0 list-none p-0 text-xs">
                {incident.relatedLinks.map((link, idx) => (
                    <li
                        key={idx}
                        className="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-border py-2 last:border-b-0"
                    >
                        <LinkIcon
                            size={11}
                            strokeWidth={1.5}
                            className="shrink-0 text-fg-3"
                        />
                        <span className="font-mono text-3xs text-fg-3">
                            {link.ts}
                        </span>
                        <span className="font-mono text-2xs text-fg-1">
                            #{link.eventId} {link.eventType}
                        </span>
                        {link.severity && (
                            <SeverityBadge level={link.severity} />
                        )}
                        {link.relationType && (
                            <span className="ml-auto text-2xs text-fg-3">
                                {RELATION_LABEL[link.relationType] ??
                                    link.relationType}
                            </span>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}
