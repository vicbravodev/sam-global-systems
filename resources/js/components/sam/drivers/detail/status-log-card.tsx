import { Calendar } from 'lucide-react';
import { SeverityBadge } from '@/components/sam/severity-badge';
import type { Severity } from '@/components/sam/severity-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import type { DriverStatusLogEntry } from '@/types/drivers';

function toSeverity(level: string | null): Severity {
    return level === 'critical' ||
        level === 'high' ||
        level === 'medium' ||
        level === 'low'
        ? level
        : 'info';
}

export function StatusLogCard({
    entries,
}: {
    entries: DriverStatusLogEntry[];
}) {
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
