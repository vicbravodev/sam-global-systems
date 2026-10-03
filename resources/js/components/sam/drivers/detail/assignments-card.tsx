import { Link } from '@inertiajs/react';
import { Truck } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { DriverAssignmentEntry } from '@/types/drivers';

const ASSIGNMENT_TYPE_LABELS: Record<string, string> = {
    primary_driver: 'Conductor principal',
    secondary_driver: 'Conductor secundario',
    temporary_operator: 'Operador temporal',
    responsible_party: 'Responsable',
};

export function AssignmentsCard({
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
                                <tr className="sam-caps sticky top-0 z-10 border-b border-border bg-surface-3">
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
