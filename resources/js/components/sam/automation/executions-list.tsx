import { Link } from '@inertiajs/react';
import { Check, History, RotateCcw, X, Zap } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import { MetaChip } from '@/components/sam/meta-chip';
import { RelativeTime } from '@/components/sam/relative-time';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { formatDateTime } from '@/lib/format';
import { postJson } from '@/lib/sam-fetch';
import { submit } from '@/lib/submit';
import { minutesSince } from '@/lib/time';
import { cn } from '@/lib/utils';
import { useAutomationBase, useTeamSlug } from './api';
import { ACTIONS, executionStatus, stepPhrase } from './copy';
import type { ExecutionRow, Option } from './types';

type ExecutionAction = 'retry' | 'confirm' | 'cancel';

const SUCCESS: Record<ExecutionAction, string> = {
    retry: 'Se volverá a intentar en unos segundos.',
    confirm: 'Acción confirmada; se ejecutará ahora.',
    cancel: 'Acción cancelada.',
};

interface ExecutionsListProps {
    executions: ExecutionRow[];
    actionTypes: Option[];
    canManage: boolean;
    filtered: boolean;
}

/**
 * Historial de acciones que hicieron las automatizaciones: qué se hizo, con
 * qué resultado, por qué automatización y sobre qué incidente, con las
 * acciones que el operador puede tomar (reintentar, confirmar, cancelar).
 */
export function ExecutionsList({
    executions,
    actionTypes,
    canManage,
    filtered,
}: ExecutionsListProps) {
    const base = useAutomationBase();
    const slug = useTeamSlug();
    const [busy, setBusy] = useState<number | null>(null);
    const [cancelling, setCancelling] = useState<ExecutionRow | null>(null);

    const act = async (execution: ExecutionRow, action: ExecutionAction) => {
        if (base === null || busy !== null) {
            return;
        }

        setBusy(execution.id);
        await submit(
            postJson(`${base}/executions/${execution.id}/${action}`, {}),
            SUCCESS[action],
        );
        setBusy(null);
    };

    if (executions.length === 0) {
        return (
            <EmptyState
                icon={History}
                title={
                    filtered
                        ? 'Nada con este filtro'
                        : 'Todavía sin ejecuciones'
                }
                description={
                    filtered
                        ? 'Ninguna acción de los últimos 30 días coincide. Prueba con «Todas».'
                        : 'Cuando una automatización encendida se active, aquí verás cada cosa que hizo y si salió bien.'
                }
            />
        );
    }

    return (
        <>
            <ul className="divide-y divide-border">
                {executions.map((execution) => {
                    const status = executionStatus(
                        execution.status,
                        execution.statusLabel,
                    );
                    const Icon =
                        ACTIONS[execution.actionType ?? '']?.icon ?? Zap;
                    const when = execution.executedAt ?? execution.createdAt;
                    const recipient =
                        execution.targetName ??
                        (execution.targetLabel !== '—'
                            ? execution.targetLabel
                            : null);

                    return (
                        <li
                            key={execution.id}
                            className="flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-center sm:gap-4"
                        >
                            <div className="flex shrink-0 items-center gap-2 sm:w-40">
                                <span
                                    className={cn(
                                        'size-2 shrink-0 rounded-full',
                                        status.dot,
                                    )}
                                    aria-hidden="true"
                                />
                                <span
                                    className={cn(
                                        'text-xs font-medium',
                                        status.text,
                                    )}
                                >
                                    {status.label}
                                </span>
                                {when && (
                                    <span
                                        className="ml-auto sm:hidden"
                                        title={formatDateTime(when)}
                                    >
                                        <RelativeTime
                                            minutes={minutesSince(when)}
                                        />
                                    </span>
                                )}
                            </div>

                            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span className="flex min-w-0 items-center gap-1.5 text-sm text-fg-1">
                                    <Icon
                                        className="size-3.5 shrink-0 text-fg-3"
                                        aria-hidden="true"
                                    />
                                    <span className="truncate">
                                        {stepPhrase(
                                            execution.actionType,
                                            recipient,
                                            actionTypes,
                                        )}
                                    </span>
                                    {execution.isStub && (
                                        <span title="Esta acción todavía no está conectada: se registró sin hacer nada real.">
                                            <MetaChip>Simulada</MetaChip>
                                        </span>
                                    )}
                                </span>
                                <span className="flex min-w-0 flex-wrap items-center gap-x-1.5 text-2xs text-fg-3">
                                    <span className="truncate">
                                        {originLabel(execution)}
                                    </span>
                                    {execution.incidentId !== null && (
                                        <>
                                            <span aria-hidden="true">·</span>
                                            {slug ? (
                                                <Link
                                                    href={`/${slug}/incidents/${execution.incidentId}`}
                                                    className="min-w-0 truncate text-fg-2 underline-offset-2 hover:text-primary hover:underline"
                                                >
                                                    {execution.incidentReference ??
                                                        'Incidente'}
                                                    {execution.incidentTitle &&
                                                        ` · ${execution.incidentTitle}`}
                                                </Link>
                                            ) : (
                                                <span>
                                                    {
                                                        execution.incidentReference
                                                    }
                                                </span>
                                            )}
                                        </>
                                    )}
                                    {execution.attempts > 1 && (
                                        <>
                                            <span aria-hidden="true">·</span>
                                            <span>
                                                {execution.attempts} intentos
                                            </span>
                                        </>
                                    )}
                                </span>
                                {execution.errorMessage &&
                                    execution.status !== 'completed' && (
                                        <span className="line-clamp-2 text-2xs text-severity-critical">
                                            {execution.errorMessage}
                                        </span>
                                    )}
                            </div>

                            {when && (
                                <span
                                    className="hidden w-20 shrink-0 text-right sm:block"
                                    title={formatDateTime(when)}
                                >
                                    <RelativeTime
                                        minutes={minutesSince(when)}
                                    />
                                </span>
                            )}

                            {canManage && (
                                <div className="flex shrink-0 items-center gap-1.5 sm:w-52 sm:justify-end">
                                    {execution.status === 'failed' && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={busy !== null}
                                            onClick={() =>
                                                void act(execution, 'retry')
                                            }
                                        >
                                            <RotateCcw size={13} />
                                            Reintentar
                                        </Button>
                                    )}
                                    {execution.status === 'pending' && (
                                        <>
                                            <Button
                                                size="sm"
                                                disabled={busy !== null}
                                                onClick={() =>
                                                    void act(
                                                        execution,
                                                        'confirm',
                                                    )
                                                }
                                            >
                                                <Check size={13} />
                                                Confirmar
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                disabled={busy !== null}
                                                onClick={() =>
                                                    setCancelling(execution)
                                                }
                                            >
                                                <X size={13} />
                                                Cancelar
                                            </Button>
                                        </>
                                    )}
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>

            <ConfirmDialog
                open={cancelling !== null}
                title="Cancelar esta acción"
                description="La acción no se hará y quedará registrada como cancelada. Esto no se puede deshacer."
                confirmLabel="Cancelar acción"
                cancelLabel="Volver"
                onConfirm={async () => {
                    if (cancelling) {
                        await act(cancelling, 'cancel');
                        setCancelling(null);
                    }
                }}
                onOpenChange={(open) => !open && setCancelling(null)}
            />
        </>
    );
}

/** De dónde salió la acción, sin exponer tipos internos. */
function originLabel(execution: ExecutionRow): string {
    if (execution.workflowName) {
        return execution.workflowName;
    }

    if (execution.sourceType === 'manual') {
        return 'Ejecutada a mano';
    }

    return execution.sourceType === 'workflow' || execution.workflowId !== null
        ? 'Automatización eliminada'
        : 'Acción automática del sistema';
}
