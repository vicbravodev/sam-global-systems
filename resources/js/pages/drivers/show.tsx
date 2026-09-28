import { Head, Link, usePage } from '@inertiajs/react';
import {
    Calendar,
    ChevronLeft,
    FileText,
    IdCard,
    MessageCircle,
    Phone,
    ShieldAlert,
    Truck,
} from 'lucide-react';
import { DriverStatusBadge } from '@/components/sam/drivers/driver-status-badge';
import { EntityAvatar } from '@/components/sam/entity-avatar';
import { LinkedIncidentsCard } from '@/components/sam/linked-incidents-card';
import { RecentEventsCard } from '@/components/sam/recent-events-card';
import { RelativeTime } from '@/components/sam/relative-time';
import {
    RISK_LEVEL_LABELS,
    RiskGauge,
    TREND_LABELS,
    resolveRiskLevel,
} from '@/components/sam/risk-gauge';
import { SeverityBadge } from '@/components/sam/severity-badge';
import type { Severity } from '@/components/sam/severity-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDate, formatDateTime } from '@/lib/format';
import { isFresh, minutesSince } from '@/lib/time';
import { cn } from '@/lib/utils';
import type {
    DriverActivityPoint,
    DriverAssignmentEntry,
    DriverContactEntry,
    DriverDetail,
    DriverDocumentEntry,
    DriverShowProps,
    DriverStatusLogEntry,
} from '@/types/drivers';

const CONTACT_TYPE_LABELS: Record<string, string> = {
    mobile_phone: 'Teléfono móvil',
    email: 'Correo',
    emergency_contact: 'Contacto de emergencia',
    supervisor_contact: 'Supervisor',
};

const DOCUMENT_TYPE_LABELS: Record<string, string> = {
    license: 'Licencia',
    identification: 'Identificación',
    medical_cert: 'Certificado médico',
    internal_doc: 'Documento interno',
    special_permit: 'Permiso especial',
};

const ASSIGNMENT_TYPE_LABELS: Record<string, string> = {
    primary_driver: 'Conductor principal',
    secondary_driver: 'Conductor secundario',
    temporary_operator: 'Operador temporal',
    responsible_party: 'Responsable',
};

const TREND_TONE: Record<string, string> = {
    deteriorating: 'text-severity-critical',
    improving: 'text-severity-low',
    stable: 'text-fg-2',
    baseline: 'text-fg-3',
};

function toSeverity(level: string | null): Severity {
    return level === 'critical' ||
        level === 'high' ||
        level === 'medium' ||
        level === 'low'
        ? level
        : 'info';
}

function digits(phone: string): string {
    return phone.replace(/[^+\d]/g, '');
}

function isPhoneContact(contact: DriverContactEntry): boolean {
    return (
        contact.contactType === 'mobile_phone' ||
        contact.contactType === 'emergency_contact' ||
        contact.contactType === 'supervisor_contact'
    );
}

// ---- Header ----

function DriverHero({
    driver,
    teamSlug,
}: {
    driver: DriverDetail;
    teamSlug: string | null;
}) {
    const phone =
        driver.phone ??
        driver.contacts.find((c) => c.contactType === 'mobile_phone')?.value ??
        null;
    const riskLevel = resolveRiskLevel(
        driver.riskProfile?.riskLevel,
        driver.riskProfile?.riskScore ?? null,
    );
    // "visto" = latest real activity (event or unit signal), not the
    // roster-sync timestamp, which lags hours behind the road.
    const seenAt = driver.lastSignalAt;
    const fresh = isFresh(seenAt);

    return (
        <header className="flex flex-wrap items-start justify-between gap-4">
            <div className="flex min-w-0 items-start gap-3">
                <Button variant="ghost" size="sm" asChild className="mt-1">
                    <Link
                        href={teamSlug ? `/${teamSlug}/drivers` : '#'}
                        aria-label="Volver a conductores"
                    >
                        <ChevronLeft size={15} />
                    </Link>
                </Button>
                <EntityAvatar name={driver.fullName} size={52} />
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2.5">
                        <h1 className="sam-h1 truncate">{driver.fullName}</h1>
                        <DriverStatusBadge status={driver.status} />
                        {riskLevel &&
                            (riskLevel === 'high' ||
                                riskLevel === 'critical') && (
                                <SeverityBadge level={riskLevel} />
                            )}
                    </div>
                    <p className="sam-meta mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                        {driver.employeeCode && (
                            <span className="font-mono">
                                {driver.employeeCode}
                            </span>
                        )}
                        {driver.currentAsset ? (
                            <Link
                                href={
                                    teamSlug
                                        ? `/${teamSlug}/assets/${driver.currentAsset.id}`
                                        : '#'
                                }
                                className="inline-flex items-center gap-1 text-fg-2 hover:text-primary hover:underline"
                            >
                                <Truck size={12} strokeWidth={1.75} />
                                {driver.currentAsset.name}
                                {driver.currentAsset.code && (
                                    <span className="font-mono text-fg-3">
                                        {driver.currentAsset.code}
                                    </span>
                                )}
                            </Link>
                        ) : (
                            <span className="italic">Sin unidad asignada</span>
                        )}
                        {seenAt && (
                            <span
                                className="inline-flex items-center gap-1.5"
                                title={formatDateTime(seenAt)}
                            >
                                <span
                                    className={cn(
                                        'size-1.5 rounded-full',
                                        fresh
                                            ? 'bg-severity-low'
                                            : 'bg-fg-disabled',
                                    )}
                                    aria-hidden="true"
                                />
                                visto{' '}
                                <RelativeTime minutes={minutesSince(seenAt)} />
                            </span>
                        )}
                    </p>
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                {phone && (
                    <>
                        <Button variant="outline" size="sm" asChild>
                            <a href={`tel:${digits(phone)}`}>
                                <Phone size={13} />
                                Llamar
                            </a>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <a
                                href={`https://wa.me/${digits(phone).replace('+', '')}`}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <MessageCircle size={13} />
                                WhatsApp
                            </a>
                        </Button>
                    </>
                )}
                {driver.currentAsset && teamSlug && (
                    <Button variant="outline" size="sm" asChild>
                        <Link
                            href={`/${teamSlug}/assets/${driver.currentAsset.id}`}
                        >
                            <Truck size={13} />
                            Ver unidad
                        </Link>
                    </Button>
                )}
            </div>
        </header>
    );
}

// ---- Risk ----

function ActivityBars({ points }: { points: DriverActivityPoint[] }) {
    const max = Math.max(1, ...points.map((p) => p.count));
    const total = points.reduce((sum, p) => sum + p.count, 0);

    return (
        <div className="flex flex-col gap-1.5">
            <div className="flex items-baseline justify-between">
                <span className="text-2xs font-semibold tracking-caps text-fg-3 uppercase">
                    Eventos · {points.length} días
                </span>
                <span className="font-mono text-2xs text-fg-2 tabular-nums">
                    {total} en total
                </span>
            </div>
            <div
                className="flex h-14 items-end gap-px"
                role="img"
                aria-label={`${total} eventos en los últimos ${points.length} días`}
            >
                {points.map((point, index) => {
                    const last = index === points.length - 1;
                    const height =
                        point.count === 0
                            ? 2
                            : Math.max(4, (point.count / max) * 56);

                    return (
                        <span
                            key={point.date}
                            title={`${formatDate(point.date)}: ${point.count}`}
                            className={cn(
                                'flex-1 rounded-t-[3px] transition-[height] duration-500 ease-(--ease-out)',
                                point.count === 0
                                    ? 'bg-surface-3'
                                    : last
                                      ? 'bg-accent'
                                      : 'bg-primary/55',
                            )}
                            style={{ height }}
                        />
                    );
                })}
            </div>
            <div className="flex justify-between text-3xs text-fg-3">
                <span>{formatDate(points[0]?.date)}</span>
                <span>hoy</span>
            </div>
        </div>
    );
}

function RiskCard({
    risk,
    activity,
}: {
    risk: DriverDetail['riskProfile'];
    activity: DriverActivityPoint[];
}) {
    const level = resolveRiskLevel(risk?.riskLevel, risk?.riskScore ?? null);
    const delta =
        risk?.previousScore !== null &&
        risk?.previousScore !== undefined &&
        risk.riskScore !== null
            ? risk.riskScore - risk.previousScore
            : null;

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <ShieldAlert size={15} /> Perfil de riesgo
                </CardTitle>
                {risk?.lastCalculatedAt && (
                    <span className="sam-meta">
                        calculado{' '}
                        <RelativeTime
                            minutes={minutesSince(risk.lastCalculatedAt)}
                        />
                    </span>
                )}
            </CardHeader>
            <CardContent className="p-4">
                {risk === null || risk.riskScore === null ? (
                    <p className="text-sm text-fg-3">
                        Sin perfil de riesgo todavía. Se calcula cada noche con
                        la actividad de manejo del conductor (incidentes,
                        maniobras bruscas, alertas de fatiga) y aparece aquí en
                        cuanto haya eventos.
                    </p>
                ) : (
                    <div className="grid gap-5 md:grid-cols-[auto_1fr]">
                        <div className="flex flex-col items-center gap-2">
                            <RiskGauge
                                score={risk.riskScore}
                                level={risk.riskLevel}
                            />
                            {risk.trend && (
                                <span
                                    className={cn(
                                        'text-2xs font-medium',
                                        TREND_TONE[risk.trend] ?? 'text-fg-3',
                                    )}
                                >
                                    {TREND_LABELS[risk.trend] ?? risk.trend}
                                    {delta !== null && delta !== 0 && (
                                        <span className="ml-1 font-mono tabular-nums">
                                            ({delta > 0 ? '+' : ''}
                                            {delta.toFixed(0)})
                                        </span>
                                    )}
                                </span>
                            )}
                            {level && (
                                <span className="text-3xs text-fg-3">
                                    ventana {risk.windowDays ?? 30} días ·{' '}
                                    {RISK_LEVEL_LABELS[level].toLowerCase()}
                                </span>
                            )}
                        </div>
                        <div className="flex min-w-0 flex-col gap-4">
                            <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                {[
                                    [
                                        'Incidentes',
                                        risk.incidentsCount,
                                        risk.incidentsCount > 0,
                                    ],
                                    [
                                        'Maniobras bruscas',
                                        risk.harshEventsCount,
                                        false,
                                    ],
                                    [
                                        'Alertas de fatiga',
                                        risk.fatigueFlagsCount,
                                        risk.fatigueFlagsCount > 0,
                                    ],
                                    [
                                        'Eventos severos',
                                        risk.severeEventsCount,
                                        risk.severeEventsCount > 0,
                                    ],
                                ].map(([label, value, hot]) => (
                                    <div
                                        key={String(label)}
                                        className="rounded-md border border-border bg-surface-2 px-3 py-2"
                                    >
                                        <dt className="text-3xs text-fg-3">
                                            {label}
                                        </dt>
                                        <dd
                                            className={cn(
                                                'font-sans text-lg font-semibold',
                                                hot
                                                    ? 'text-severity-high'
                                                    : 'text-fg-1',
                                            )}
                                        >
                                            {value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                            {activity.length > 1 && (
                                <ActivityBars points={activity} />
                            )}
                        </div>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

// ---- Profile ----

function ProfileField({
    label,
    children,
    mono = false,
}: {
    label: string;
    children: React.ReactNode;
    mono?: boolean;
}) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-2xs tracking-caps text-fg-3 uppercase">
                {label}
            </dt>
            <dd className={cn('text-sm text-fg-1', mono && 'font-mono')}>
                {children}
            </dd>
        </div>
    );
}

function ProfileCard({ driver }: { driver: DriverDetail }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <IdCard size={15} /> Perfil
                </CardTitle>
            </CardHeader>
            <CardContent className="p-4">
                <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
                    <ProfileField label="Nombre">
                        {driver.firstName ?? driver.fullName}
                    </ProfileField>
                    <ProfileField label="Apellidos">
                        {driver.lastName ?? '—'}
                    </ProfileField>
                    <ProfileField label="Código de empleado" mono>
                        {driver.employeeCode ?? '—'}
                    </ProfileField>
                    <ProfileField label="ID en proveedor" mono>
                        {driver.externalPrimaryId ?? '—'}
                    </ProfileField>
                    {driver.providerFields.map((field) => (
                        <ProfileField
                            key={field.key}
                            label={field.label}
                            mono={field.key === 'license_number'}
                        >
                            {field.value}
                        </ProfileField>
                    ))}
                    <ProfileField label="Primera conexión">
                        {formatDate(driver.firstSeenAt)}
                    </ProfileField>
                    <ProfileField label="Última conexión">
                        {formatDate(driver.lastSeenAt)}
                    </ProfileField>
                </dl>
            </CardContent>
        </Card>
    );
}

// ---- Contacts ----

function ContactsCard({ contacts }: { contacts: DriverContactEntry[] }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <Phone size={15} /> Contactos
                </CardTitle>
                <span className="sam-meta">
                    {contacts.length}{' '}
                    {contacts.length === 1 ? 'contacto' : 'contactos'}
                </span>
            </CardHeader>
            <CardContent className="p-0">
                {contacts.length === 0 ? (
                    <p className="px-4 py-5 text-sm text-fg-3">
                        Sin contactos. Se sincronizan desde el proveedor de
                        telemetría o se cargan a mano; el contacto de emergencia
                        es el que usa la escalación.
                    </p>
                ) : (
                    <ul className="divide-y divide-border">
                        {contacts.map((contact) => (
                            <li
                                key={contact.id}
                                className="flex items-center gap-3 px-4 py-2.5"
                            >
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span className="text-2xs text-fg-3">
                                        {CONTACT_TYPE_LABELS[
                                            contact.contactType
                                        ] ?? contact.contactType}
                                        {contact.label && ` · ${contact.label}`}
                                    </span>
                                    {isPhoneContact(contact) ? (
                                        <a
                                            href={`tel:${digits(contact.value)}`}
                                            className="truncate font-mono text-sm text-fg-1 tabular-nums hover:text-primary"
                                        >
                                            {contact.value}
                                        </a>
                                    ) : contact.contactType === 'email' ? (
                                        <a
                                            href={`mailto:${contact.value}`}
                                            className="truncate text-sm text-fg-1 hover:text-primary"
                                        >
                                            {contact.value}
                                        </a>
                                    ) : (
                                        <span className="truncate text-sm text-fg-1">
                                            {contact.value}
                                        </span>
                                    )}
                                </span>
                                {contact.isPrimary && (
                                    <span className="rounded-sm border border-primary/40 bg-primary/10 px-1.5 py-0.5 text-3xs font-semibold text-primary">
                                        Primario
                                    </span>
                                )}
                                {contact.isEmergency && (
                                    <span className="rounded-sm border border-severity-critical/40 bg-severity-critical/10 px-1.5 py-0.5 text-3xs font-semibold text-severity-critical">
                                        Emergencia
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

// ---- Documents ----

function ExpiryChip({ document }: { document: DriverDocumentEntry }) {
    if (document.isExpired || document.status === 'expired') {
        return (
            <span className="rounded-sm border border-severity-critical/40 bg-severity-critical/10 px-1.5 py-0.5 text-3xs font-semibold text-severity-critical">
                Vencido
            </span>
        );
    }

    if (document.daysToExpiry !== null && document.daysToExpiry <= 30) {
        return (
            <span className="rounded-sm border border-severity-medium/40 bg-severity-medium/10 px-1.5 py-0.5 text-3xs font-semibold text-severity-medium">
                Vence en {document.daysToExpiry} d
            </span>
        );
    }

    if (document.status === 'pending_renewal') {
        return (
            <span className="rounded-sm border border-severity-medium/40 bg-severity-medium/10 px-1.5 py-0.5 text-3xs font-semibold text-severity-medium">
                Por renovar
            </span>
        );
    }

    return (
        <span className="rounded-sm border border-border bg-surface-3 px-1.5 py-0.5 text-3xs font-semibold text-fg-3">
            Vigente
        </span>
    );
}

function DocumentsCard({ documents }: { documents: DriverDocumentEntry[] }) {
    const expiring = documents.filter(
        (d) => d.isExpired || (d.daysToExpiry !== null && d.daysToExpiry <= 30),
    ).length;

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <FileText size={15} /> Documentos
                </CardTitle>
                <span
                    className={cn(
                        'sam-meta',
                        expiring > 0 && 'font-medium text-severity-medium',
                    )}
                >
                    {expiring > 0
                        ? `${expiring} por vencer`
                        : `${documents.length} ${documents.length === 1 ? 'documento' : 'documentos'}`}
                </span>
            </CardHeader>
            <CardContent className="p-0">
                {documents.length === 0 ? (
                    <p className="px-4 py-5 text-sm text-fg-3">
                        Sin documentos. Licencias y certificados se sincronizan
                        desde el proveedor o se cargan a mano.
                    </p>
                ) : (
                    <ul className="divide-y divide-border">
                        {documents.map((document) => (
                            <li
                                key={document.id}
                                className="flex items-center gap-3 px-4 py-2.5"
                            >
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span className="text-sm text-fg-1">
                                        {DOCUMENT_TYPE_LABELS[
                                            document.documentType
                                        ] ?? document.documentType}
                                        {document.documentNumber && (
                                            <span className="ml-2 font-mono text-2xs text-fg-3">
                                                {document.documentNumber}
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-2xs text-fg-3">
                                        {document.expiresAt
                                            ? `vence ${formatDate(document.expiresAt)}`
                                            : 'sin fecha de vencimiento'}
                                    </span>
                                </span>
                                <ExpiryChip document={document} />
                                {document.fileUrl && (
                                    <a
                                        href={document.fileUrl}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="text-2xs text-primary hover:underline"
                                    >
                                        Ver
                                    </a>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

// ---- Assignments ----

function AssignmentsCard({
    assignments,
    teamSlug,
}: {
    assignments: DriverAssignmentEntry[];
    teamSlug: string | null;
}) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <Truck size={15} /> Historial de unidades
                </CardTitle>
                <span className="sam-meta">últimas {assignments.length}</span>
            </CardHeader>
            <CardContent className="p-0">
                {assignments.length === 0 ? (
                    <p className="px-4 py-5 text-sm text-fg-3">
                        Sin asignaciones. Aparecen cuando el conductor se
                        vincula a un vehículo de la flota.
                    </p>
                ) : (
                    <div className="max-h-96 overflow-auto">
                        <table className="w-full border-collapse">
                            <thead>
                                <tr className="sticky top-0 z-10 border-b border-border bg-surface-3 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                                    <th className="px-4 py-2 text-left">
                                        Unidad
                                    </th>
                                    <th className="w-44 px-2.5 py-2 text-left">
                                        Tipo
                                    </th>
                                    <th className="w-36 px-2.5 py-2 text-left">
                                        Inicio
                                    </th>
                                    <th className="w-36 px-2.5 py-2 text-left">
                                        Fin
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {assignments.map((assignment) => (
                                    <tr
                                        key={assignment.id}
                                        className={cn(
                                            'border-b border-border',
                                            assignment.isCurrent &&
                                                'bg-severity-low/5',
                                        )}
                                    >
                                        <td className="px-4 py-2">
                                            {assignment.asset ? (
                                                <Link
                                                    href={
                                                        teamSlug
                                                            ? `/${teamSlug}/assets/${assignment.asset.id}`
                                                            : '#'
                                                    }
                                                    className="text-xs text-fg-1 hover:text-primary hover:underline"
                                                >
                                                    {assignment.asset.name}
                                                    {assignment.asset.code && (
                                                        <span className="ml-1.5 font-mono text-3xs text-fg-3">
                                                            {
                                                                assignment.asset
                                                                    .code
                                                            }
                                                        </span>
                                                    )}
                                                </Link>
                                            ) : (
                                                <span className="text-fg-3">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-2.5 py-2 text-xs text-fg-2">
                                            {ASSIGNMENT_TYPE_LABELS[
                                                assignment.assignmentType
                                            ] ?? assignment.assignmentType}
                                        </td>
                                        <td className="px-2.5 py-2 text-2xs text-fg-2">
                                            {formatDate(assignment.startedAt)}
                                        </td>
                                        <td className="px-2.5 py-2 text-2xs">
                                            {assignment.isCurrent ? (
                                                <span className="rounded-sm border border-severity-low/40 bg-severity-low/10 px-1.5 py-0.5 text-3xs font-semibold text-severity-low">
                                                    Vigente
                                                </span>
                                            ) : (
                                                <span className="text-fg-2">
                                                    {formatDate(
                                                        assignment.endedAt,
                                                    )}
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

// ---- Status log ----

function StatusLogCard({ entries }: { entries: DriverStatusLogEntry[] }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <Calendar size={15} /> Historial de estado
                </CardTitle>
                <span className="sam-meta">últimos {entries.length}</span>
            </CardHeader>
            <CardContent className="p-0">
                {entries.length === 0 ? (
                    <p className="px-4 py-5 text-sm text-fg-3">
                        Sin cambios registrados. Aquí queda la disponibilidad
                        del conductor (activo, fuera de turno, suspendido).
                    </p>
                ) : (
                    <ul className="divide-y divide-border">
                        {entries.map((entry) => (
                            <li
                                key={entry.id}
                                className="flex items-center gap-3 px-4 py-2.5"
                            >
                                <SeverityBadge
                                    level={toSeverity(entry.severity)}
                                />
                                <span className="flex-1 text-xs text-fg-1">
                                    {entry.statusLabel ?? entry.statusCode}
                                </span>
                                <span className="text-2xs text-fg-3">
                                    {formatDate(entry.effectiveFrom)}
                                    {entry.effectiveTo &&
                                        ` → ${formatDate(entry.effectiveTo)}`}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

// ---- Main page ----

export default function DriverShow() {
    const page = usePage();
    const {
        driver,
        assignments,
        statusLog,
        recentEvents,
        incidents,
        activity,
    } = page.props as unknown as DriverShowProps;
    const teamSlug = page.props.currentTeam?.slug ?? null;

    return (
        <>
            <Head title={`${driver.fullName} - Conductores`} />
            <div className="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 md:p-6">
                <DriverHero driver={driver} teamSlug={teamSlug} />

                {/* Operación a la izquierda (riesgo, actividad, incidentes,
                    unidades); ficha a la derecha (perfil, contactos,
                    documentos, estado). */}
                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                    <div className="flex min-w-0 flex-col gap-4">
                        <RiskCard
                            risk={driver.riskProfile}
                            activity={activity ?? []}
                        />
                        <RecentEventsCard
                            events={recentEvents ?? []}
                            teamSlug={teamSlug}
                            subject="Este conductor"
                        />
                        <LinkedIncidentsCard
                            incidents={incidents ?? []}
                            teamSlug={teamSlug}
                            subject="Este conductor"
                        />
                        <AssignmentsCard
                            assignments={assignments}
                            teamSlug={teamSlug}
                        />
                    </div>
                    <div className="flex min-w-0 flex-col gap-4">
                        <ProfileCard driver={driver} />
                        <ContactsCard contacts={driver.contacts} />
                        <DocumentsCard documents={driver.documents} />
                        <StatusLogCard entries={statusLog} />
                    </div>
                </div>
            </div>
        </>
    );
}

DriverShow.layout = (props: {
    currentTeam?: { slug: string } | null;
    driver?: { id: number; fullName: string } | null;
}) => ({
    breadcrumbs: [
        {
            title: 'Conductores',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/drivers`
                : '/drivers',
        },
        ...(props.driver
            ? [
                  {
                      title: props.driver.fullName,
                      href: props.currentTeam
                          ? `/${props.currentTeam.slug}/drivers/${props.driver.id}`
                          : '#',
                  },
              ]
            : []),
    ],
});
