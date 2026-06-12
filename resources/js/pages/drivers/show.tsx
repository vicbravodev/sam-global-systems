import { Head, Link, usePage } from '@inertiajs/react';
import {
    ChevronLeft,
    FileText,
    Phone,
    ShieldAlert,
    Truck,
    User,
} from 'lucide-react';
import { DriverStatusBadge } from '@/components/sam/drivers/driver-status-badge';
import { RelativeTime } from '@/components/sam/relative-time';
import { SeverityBadge } from '@/components/sam/severity-badge';
import type { Severity } from '@/components/sam/severity-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type {
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

const DOCUMENT_STATUS_LABELS: Record<string, string> = {
    valid: 'Vigente',
    expired: 'Vencido',
    pending_renewal: 'Por renovar',
};

const ASSIGNMENT_TYPE_LABELS: Record<string, string> = {
    primary_driver: 'Conductor principal',
    secondary_driver: 'Conductor secundario',
    temporary_operator: 'Operador temporal',
    responsible_party: 'Responsable',
};

function minutesSince(iso: string): number {
    return Math.max(0, Math.floor((Date.now() - Date.parse(iso)) / 60000));
}

function formatDate(iso: string | null): string {
    if (iso === null) {
        return '—';
    }

    return new Date(iso).toLocaleDateString('es', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

function toSeverity(level: string | null): Severity {
    return level === 'critical' ||
        level === 'high' ||
        level === 'medium' ||
        level === 'low'
        ? level
        : 'info';
}

// ---- Cards ----

function RiskCard({ risk }: { risk: DriverDetail['riskProfile'] }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <ShieldAlert size={15} /> Perfil de riesgo
                </CardTitle>
                {risk?.lastCalculatedAt && (
                    <RelativeTime
                        minutes={minutesSince(risk.lastCalculatedAt)}
                    />
                )}
            </CardHeader>
            <CardContent className="p-4">
                {risk === null ? (
                    <p className="text-sm text-fg-3">
                        Sin perfil de riesgo calculado todavía.
                    </p>
                ) : (
                    <div className="flex flex-col gap-3">
                        <div className="flex items-center gap-3">
                            <span
                                className={cn(
                                    'font-mono text-2xl font-semibold tabular-nums',
                                    (risk.riskScore ?? 0) >= 70
                                        ? 'text-severity-critical'
                                        : (risk.riskScore ?? 0) >= 40
                                          ? 'text-severity-medium'
                                          : 'text-severity-low',
                                )}
                            >
                                {risk.riskScore !== null
                                    ? risk.riskScore.toFixed(0)
                                    : '—'}
                            </span>
                            {risk.riskLevel && (
                                <SeverityBadge
                                    level={toSeverity(risk.riskLevel)}
                                />
                            )}
                        </div>
                        <dl className="grid grid-cols-3 gap-2 text-center">
                            {[
                                ['Incidentes', risk.incidentsCount],
                                ['Eventos bruscos', risk.harshEventsCount],
                                ['Alertas de fatiga', risk.fatigueFlagsCount],
                            ].map(([label, value]) => (
                                <div
                                    key={label}
                                    className="rounded-md border border-border bg-surface-2 px-2 py-2"
                                >
                                    <dt className="text-3xs text-fg-3">
                                        {label}
                                    </dt>
                                    <dd className="font-mono text-md font-semibold text-fg-1 tabular-nums">
                                        {value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

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
                    <p className="px-4 py-6 text-sm text-fg-3">
                        Sin contactos registrados.
                    </p>
                ) : (
                    <ul className="divide-y divide-border">
                        {contacts.map((contact) => (
                            <li
                                key={contact.id}
                                className="flex items-center gap-3 px-4 py-2.5"
                            >
                                <span className="w-44 shrink-0 text-xs text-fg-2">
                                    {CONTACT_TYPE_LABELS[contact.contactType] ??
                                        contact.contactType}
                                    {contact.label && (
                                        <span className="text-fg-3">
                                            {' '}
                                            · {contact.label}
                                        </span>
                                    )}
                                </span>
                                <span className="flex-1 font-mono text-xs text-fg-1 tabular-nums">
                                    {contact.value}
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

function DocumentsCard({ documents }: { documents: DriverDocumentEntry[] }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <FileText size={15} /> Documentos
                </CardTitle>
                <span className="sam-meta">
                    {documents.length}{' '}
                    {documents.length === 1 ? 'documento' : 'documentos'}
                </span>
            </CardHeader>
            <CardContent className="p-0">
                {documents.length === 0 ? (
                    <p className="px-4 py-6 text-sm text-fg-3">
                        Sin documentos registrados.
                    </p>
                ) : (
                    <ul className="divide-y divide-border">
                        {documents.map((document) => (
                            <li
                                key={document.id}
                                className="flex items-center gap-3 px-4 py-2.5"
                            >
                                <span className="w-44 shrink-0 text-xs text-fg-2">
                                    {DOCUMENT_TYPE_LABELS[
                                        document.documentType
                                    ] ?? document.documentType}
                                </span>
                                <span className="flex-1 font-mono text-2xs text-fg-2">
                                    {document.documentNumber ?? '—'}
                                </span>
                                <span className="text-2xs text-fg-3">
                                    vence {formatDate(document.expiresAt)}
                                </span>
                                <span
                                    className={cn(
                                        'rounded-sm border px-1.5 py-0.5 text-3xs font-semibold',
                                        document.isExpired ||
                                            document.status === 'expired'
                                            ? 'border-severity-critical/40 bg-severity-critical/10 text-severity-critical'
                                            : document.status ===
                                                'pending_renewal'
                                              ? 'border-severity-medium/40 bg-severity-medium/10 text-severity-medium'
                                              : 'border-border bg-surface-3 text-fg-3',
                                    )}
                                >
                                    {document.isExpired
                                        ? DOCUMENT_STATUS_LABELS.expired
                                        : (DOCUMENT_STATUS_LABELS[
                                              document.status
                                          ] ?? document.status)}
                                </span>
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
                    <Truck size={15} /> Historial de asignaciones
                </CardTitle>
                <span className="sam-meta">últimas {assignments.length}</span>
            </CardHeader>
            <CardContent className="p-0">
                {assignments.length === 0 ? (
                    <p className="px-4 py-6 text-sm text-fg-3">
                        Sin asignaciones registradas.
                    </p>
                ) : (
                    <div className="max-h-96 overflow-auto">
                        <table className="w-full border-collapse">
                            <thead>
                                <tr className="sticky top-0 z-10 border-b border-border bg-surface-3 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                                    <th className="px-4 py-2 text-left">
                                        Asset
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
                                        className="border-b border-border"
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

function StatusLogCard({ entries }: { entries: DriverStatusLogEntry[] }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0">
                    Historial de estado
                </CardTitle>
                <span className="sam-meta">últimos {entries.length}</span>
            </CardHeader>
            <CardContent className="p-0">
                {entries.length === 0 ? (
                    <p className="px-4 py-6 text-sm text-fg-3">
                        Sin cambios de estado registrados.
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
    const { driver, assignments, statusLog } =
        page.props as unknown as DriverShowProps;
    const teamSlug = page.props.currentTeam?.slug ?? null;

    return (
        <>
            <Head title={`${driver.fullName} - Conductores`} />
            <div className="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 md:p-6">
                {/* Header */}
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button variant="ghost" size="sm" asChild>
                            <Link
                                href={teamSlug ? `/${teamSlug}/drivers` : '#'}
                                aria-label="Volver a conductores"
                            >
                                <ChevronLeft size={15} />
                            </Link>
                        </Button>
                        <div className="grid size-10 shrink-0 place-items-center rounded-md border border-border bg-surface-2 text-fg-3">
                            <User size={18} strokeWidth={1.5} />
                        </div>
                        <div>
                            <div className="flex items-center gap-2.5">
                                <h1 className="sam-h1">{driver.fullName}</h1>
                                <DriverStatusBadge status={driver.status} />
                            </div>
                            <p className="sam-meta mt-0.5">
                                {driver.employeeCode && (
                                    <span className="font-mono">
                                        {driver.employeeCode}
                                    </span>
                                )}
                                {driver.employeeCode &&
                                    driver.currentAsset &&
                                    ' · '}
                                {driver.currentAsset && (
                                    <Link
                                        href={
                                            teamSlug
                                                ? `/${teamSlug}/assets/${driver.currentAsset.id}`
                                                : '#'
                                        }
                                        className="hover:text-primary hover:underline"
                                    >
                                        {driver.currentAsset.name}
                                    </Link>
                                )}
                                {driver.externalPrimaryId && (
                                    <span className="font-mono">
                                        {' '}
                                        · {driver.externalPrimaryId}
                                    </span>
                                )}
                            </p>
                        </div>
                    </div>
                    {driver.lastSeenAt && (
                        <span className="sam-meta">
                            Visto{' '}
                            <RelativeTime
                                minutes={minutesSince(driver.lastSeenAt)}
                            />
                        </span>
                    )}
                </header>

                <div className="grid gap-4 lg:grid-cols-2">
                    <RiskCard risk={driver.riskProfile} />
                    <ContactsCard contacts={driver.contacts} />
                </div>

                <DocumentsCard documents={driver.documents} />
                <AssignmentsCard
                    assignments={assignments}
                    teamSlug={teamSlug}
                />
                <StatusLogCard entries={statusLog} />
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
