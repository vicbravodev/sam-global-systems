import { router } from '@inertiajs/react';
import { Mail, MailWarning, Phone, Truck } from 'lucide-react';
import { useState } from 'react';
import { MetaChip } from '@/components/sam/meta-chip';
import { StatusBadge } from '@/components/sam/status-badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { relativeLabel } from '@/lib/time';
import { TONE_TEXT } from '@/lib/tone';
import { cn } from '@/lib/utils';
import { update } from '@/routes/admin/demo-requests';
import { DEMO_REQUEST_STATUS } from './copy';
import type { DemoRequestStatus } from './copy';
import type { DemoRequestRow } from './types';

/** Siguiente paso del seguimiento: nueva → contactada → cerrada. */
const NEXT_ACTION: Record<
    DemoRequestStatus,
    { status: DemoRequestStatus; label: string }
> = {
    new: { status: 'contacted', label: 'Marcar contactada' },
    contacted: { status: 'closed', label: 'Cerrar' },
    closed: { status: 'new', label: 'Reabrir' },
};

export function DemoRequestItem({ request }: { request: DemoRequestRow }) {
    const [processing, setProcessing] = useState(false);
    const next = NEXT_ACTION[request.status];

    const move = () =>
        router.put(
            update(request.id).url,
            { status: next.status },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );

    return (
        <li className="grid gap-3 px-4 py-4 sm:grid-cols-[1fr_auto]">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="sam-h4 truncate">{request.company}</span>
                    <StatusBadge
                        size="sm"
                        {...DEMO_REQUEST_STATUS[request.status]}
                    />
                    <MetaChip>
                        <Truck className="size-3" />
                        {request.fleetSize} unidades
                    </MetaChip>
                </div>
                <p className="mt-1 text-sm text-fg-2">{request.name}</p>
                <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    <a
                        href={`mailto:${request.email}`}
                        className="inline-flex items-center gap-1.5 text-primary hover:underline"
                    >
                        <Mail className="size-3.5" />
                        {request.email}
                    </a>
                    {request.phone ? (
                        <a
                            href={`tel:${request.phone.replace(/[^0-9+]/g, '')}`}
                            className="inline-flex items-center gap-1.5 text-primary hover:underline"
                        >
                            <Phone className="size-3.5" />
                            {request.phone}
                        </a>
                    ) : null}
                </div>
                {request.message ? (
                    <p className="mt-2 max-w-3xl rounded-md bg-surface-2 px-3 py-2 text-sm whitespace-pre-line text-fg-2">
                        {request.message}
                    </p>
                ) : null}
                <p className="sam-meta mt-2">
                    Llegó {relativeLabel(request.createdAt, 'long')} ·{' '}
                    {formatDateTime(request.createdAt)}
                    {request.handledBy && request.statusChangedAt
                        ? ` · ${DEMO_REQUEST_STATUS[request.status].label.toLowerCase()} por ${request.handledBy} ${relativeLabel(request.statusChangedAt, 'long')}`
                        : ''}
                </p>
                {request.notified ? null : (
                    <p
                        className={cn(
                            'mt-1 inline-flex items-center gap-1.5 text-xs',
                            TONE_TEXT.warn,
                        )}
                    >
                        <MailWarning className="size-3.5" />
                        El correo de aviso no salió: revisa la configuración de
                        correo.
                    </p>
                )}
            </div>
            <div className="flex items-start sm:justify-end">
                <Button
                    size="sm"
                    variant={request.status === 'new' ? 'default' : 'outline'}
                    disabled={processing}
                    onClick={move}
                >
                    {processing && <Spinner />}
                    {next.label}
                </Button>
            </div>
        </li>
    );
}
