import { Head } from '@inertiajs/react';

interface AuditEntry {
    id: number;
    action: string;
    category: string;
    summary: string;
    team: string | null;
    actorEmail: string | null;
    occurredAt: string | null;
}

interface AdminAuditIndexProps {
    entries: AuditEntry[];
}

function formatDateTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime())
        ? '—'
        : date.toLocaleString('es', {
              day: '2-digit',
              month: '2-digit',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          });
}

export default function AdminAuditIndex({ entries }: AdminAuditIndexProps) {
    return (
        <div className="flex h-full flex-col overflow-hidden">
            <Head title="Auditoría" />

            <header className="flex shrink-0 items-center justify-between gap-3 border-b border-border bg-surface-1 px-5 py-3">
                <div className="flex items-center gap-3">
                    <h1 className="sam-h2 m-0">Auditoría</h1>
                    <span className="sam-meta">
                        seguridad y facturación · cross-tenant
                    </span>
                </div>
            </header>

            <div className="flex-1 overflow-y-auto p-5">
                {entries.length === 0 ? (
                    <p className="text-sm text-fg-3">Sin eventos.</p>
                ) : (
                    <div className="overflow-hidden rounded-md border border-border">
                        <table className="w-full text-sm">
                            <thead className="bg-surface-2 text-left">
                                <tr className="sam-meta">
                                    <th className="px-3 py-2 font-medium">
                                        Cuándo
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Acción
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Tenant
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Actor
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Resumen
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {entries.map((entry) => (
                                    <tr
                                        key={entry.id}
                                        className="border-t border-border align-top"
                                    >
                                        <td className="px-3 py-2 whitespace-nowrap tabular-nums">
                                            {formatDateTime(entry.occurredAt)}
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className="font-mono text-xs">
                                                {entry.action}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2">
                                            {entry.team ?? '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {entry.actorEmail ?? '—'}
                                        </td>
                                        <td className="px-3 py-2 text-fg-2">
                                            {entry.summary}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
}
