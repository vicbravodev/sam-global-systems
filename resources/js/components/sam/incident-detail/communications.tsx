import { Link } from '@inertiajs/react';
import { Bell, PhoneCall } from 'lucide-react';
import { formatDateTime } from '@/lib/format';
import type { IncidentCommunications } from '@/types/sam';

const CALL_STATUS_LABEL: Record<string, string> = {
    pending: 'Pendiente',
    calling: 'Llamando',
    answered: 'Contestada',
    no_answer: 'Sin respuesta',
    failed: 'Fallida',
};

const CALL_OUTCOME_LABEL: Record<string, string> = {
    confirmed_real: 'Confirmó emergencia',
    confirmed_false: 'Descartó (falsa alarma)',
    no_answer: 'Sin respuesta',
};

function deliverySummary(delivered: number, failed: number, total: number) {
    if (total === 0) {
        return 'Sin entregas';
    }

    const parts = [`${delivered}/${total} entregadas`];

    if (failed > 0) {
        parts.push(`${failed} fallidas`);
    }

    return parts.join(' · ');
}

interface CommunicationsProps {
    communications: IncidentCommunications | null | undefined;
    teamSlug: string | null;
}

/**
 * Llamadas de verificación y notificaciones disparadas por el incidente, con
 * enlace al detalle de entregas.
 */
export function Communications({
    communications,
    teamSlug,
}: CommunicationsProps) {
    const calls = communications?.verificationCalls ?? [];
    const notifications = communications?.notifications ?? [];

    if (calls.length === 0 && notifications.length === 0) {
        return null;
    }

    return (
        <section>
            <h3 className="mb-2 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                Comunicaciones
            </h3>
            <ul className="m-0 flex list-none flex-col gap-1.5 p-0 text-xs">
                {calls.map((call) => (
                    <li
                        key={`call-${call.id}`}
                        className="flex items-start gap-2 rounded-md border border-border bg-surface-1 p-2.5"
                    >
                        <PhoneCall
                            size={12}
                            strokeWidth={1.5}
                            className="mt-0.5 shrink-0 text-fg-3"
                        />
                        <div className="min-w-0 flex-1">
                            <div className="font-medium text-fg-1">
                                Llamada de verificación · intento {call.attempt}
                            </div>
                            <div className="text-2xs text-fg-3">
                                {(call.outcome &&
                                    CALL_OUTCOME_LABEL[call.outcome]) ??
                                    (call.status &&
                                        CALL_STATUS_LABEL[call.status]) ??
                                    '—'}
                                {call.phone ? ` · ${call.phone}` : ''}
                                {call.placedAt
                                    ? ` · ${formatDateTime(call.placedAt)}`
                                    : ''}
                            </div>
                        </div>
                    </li>
                ))}
                {notifications.map((notification) => (
                    <li
                        key={`notification-${notification.id}`}
                        className="flex items-start gap-2 rounded-md border border-border bg-surface-1 p-2.5"
                    >
                        <Bell
                            size={12}
                            strokeWidth={1.5}
                            className="mt-0.5 shrink-0 text-fg-3"
                        />
                        <div className="min-w-0 flex-1">
                            {teamSlug ? (
                                <Link
                                    href={`/${teamSlug}/notifications/${notification.id}`}
                                    className="block truncate font-medium text-fg-1 hover:underline"
                                >
                                    {notification.subject}
                                </Link>
                            ) : (
                                <span className="block truncate font-medium text-fg-1">
                                    {notification.subject}
                                </span>
                            )}
                            <div className="text-2xs text-fg-3">
                                {deliverySummary(
                                    notification.delivered,
                                    notification.failed,
                                    notification.deliveries,
                                )}
                                {notification.createdAt
                                    ? ` · ${formatDateTime(notification.createdAt)}`
                                    : ''}
                            </div>
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}
