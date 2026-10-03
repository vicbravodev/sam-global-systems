import { FileText } from 'lucide-react';
import { DOCUMENT_TYPE_LABELS } from '@/components/sam/drivers/copy';
import { StatusBadge } from '@/components/sam/status-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { DriverDocumentEntry } from '@/types/drivers';

function ExpiryChip({ document }: { document: DriverDocumentEntry }) {
    if (document.isExpired || document.status === 'expired') {
        return <StatusBadge size="sm" tone="critical" label="Vencido" />;
    }

    if (document.daysToExpiry !== null && document.daysToExpiry <= 30) {
        return (
            <StatusBadge
                size="sm"
                tone="warn"
                label={`Vence en ${document.daysToExpiry} d`}
            />
        );
    }

    if (document.status === 'pending_renewal') {
        return <StatusBadge size="sm" tone="warn" label="Por renovar" />;
    }

    return (
        <span className="rounded-sm border border-border bg-surface-3 px-1.5 py-0.5 text-3xs font-semibold text-fg-3">
            Vigente
        </span>
    );
}

export function DocumentsCard({
    documents,
}: {
    documents: DriverDocumentEntry[];
}) {
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
