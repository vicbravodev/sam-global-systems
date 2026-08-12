import {
    Check,
    Hand,
    Loader2,
    RefreshCw,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { TERMINAL_STATUSES } from '@/components/sam';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useInitials } from '@/hooks/use-initials';
import type { IncidentDetail } from '@/types/sam';
import { useIncidentActions } from './incident-actions-context';
import { UserAvatar } from './user-avatar';

// ---- AssigneeMenu ----

function AssigneeMenu({
    label,
    variant,
}: {
    label: string;
    variant: 'link' | 'button';
}) {
    const { members, currentUserId, assignTo, assignToMe, pending } =
        useIncidentActions();
    const getInitials = useInitials();
    const busy = pending === 'assign';

    const trigger =
        variant === 'button' ? (
            <Button size="sm" variant="default" disabled={busy}>
                {busy ? <Loader2 size={12} className="animate-spin" /> : null}
                {label}
            </Button>
        ) : (
            <button
                type="button"
                disabled={busy}
                className="inline-flex cursor-pointer items-center gap-1 border-none bg-transparent text-xs font-medium whitespace-nowrap text-fg-2 hover:text-fg-1 disabled:opacity-50"
            >
                {busy ? <Loader2 size={11} className="animate-spin" /> : null}
                {label}
            </button>
        );

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>{trigger}</DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="max-h-72 overflow-y-auto"
            >
                <DropdownMenuItem onSelect={() => void assignToMe()}>
                    Asignarme a mí
                </DropdownMenuItem>
                {members.length > 0 && <DropdownMenuSeparator />}
                {members.length > 0 && (
                    <DropdownMenuLabel>Asignar a…</DropdownMenuLabel>
                )}
                {members.map((member) => (
                    <DropdownMenuItem
                        key={member.id}
                        onSelect={() => void assignTo(member.id)}
                    >
                        <UserAvatar
                            initials={getInitials(member.name)}
                            size={20}
                        />
                        <span className="truncate">{member.name}</span>
                        {member.id === currentUserId && (
                            <span className="ml-auto text-3xs text-fg-3">
                                tú
                            </span>
                        )}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

// ---- ResolveDialog ----

const RESOLUTION_OPTIONS: { value: string; label: string }[] = [
    { value: 'handled_successfully', label: 'Resuelto correctamente' },
    { value: 'operator_confirmed_safe', label: 'Operador confirmó seguro' },
    { value: 'escalated_externally', label: 'Escalado externamente' },
    { value: 'duplicate_incident', label: 'Incidente duplicado' },
    { value: 'unresolved_closed', label: 'Cerrado sin resolver' },
];

function ResolveDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { resolve, pending } = useIncidentActions();
    const [code, setCode] = useState('handled_successfully');
    const [summary, setSummary] = useState('');
    const busy = pending === 'resolve';

    const submit = async () => {
        if (summary.trim() === '') {
            return;
        }

        const ok = await resolve({
            resolutionCode: code,
            summary: summary.trim(),
        });

        if (ok) {
            onOpenChange(false);
            setSummary('');
            setCode('handled_successfully');
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Resolver incidente</DialogTitle>
                </DialogHeader>
                <div className="flex flex-col gap-3">
                    <label className="flex flex-col gap-1 text-xs text-fg-2">
                        Código de resolución
                        <Select value={code} onValueChange={setCode}>
                            <SelectTrigger className="h-9">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {RESOLUTION_OPTIONS.map((o) => (
                                    <SelectItem key={o.value} value={o.value}>
                                        {o.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </label>
                    <label className="flex flex-col gap-1 text-xs text-fg-2">
                        Resumen
                        <textarea
                            value={summary}
                            onChange={(e) => setSummary(e.target.value)}
                            rows={3}
                            placeholder="Describe cómo se resolvió…"
                            className="resize-none rounded-md border border-border bg-surface-1 px-2.5 py-1.5 text-sm text-fg-1 outline-none placeholder:text-fg-3"
                        />
                    </label>
                </div>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancelar
                    </Button>
                    <Button
                        size="sm"
                        onClick={() => void submit()}
                        disabled={busy || summary.trim() === ''}
                    >
                        {busy ? (
                            <Loader2 size={13} className="animate-spin" />
                        ) : null}
                        Resolver
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ---- Management ----

interface ManagementProps {
    incident: IncidentDetail;
}

/**
 * Asignación + toma humana + acciones del incidente en una sola tarjeta:
 * quién lo lleva y qué se puede hacer, sin repartirlo en dos secciones.
 */
export function Management({ incident }: ManagementProps) {
    const {
        reopen,
        acknowledge,
        claim,
        release,
        escalate,
        discard,
        pending,
        currentUserId,
    } = useIncidentActions();
    const [resolveOpen, setResolveOpen] = useState(false);

    const claimedByMe =
        incident.claimedBy !== null && incident.claimedBy.id === currentUserId;
    const claimedByOther = incident.claimedBy !== null && !claimedByMe;
    const isTerminal = TERMINAL_STATUSES.includes(incident.status);

    const quietButton =
        'inline-flex cursor-pointer items-center gap-1.5 border-none bg-transparent py-1 text-xs font-medium text-fg-2 hover:text-fg-1 disabled:cursor-not-allowed disabled:opacity-40';

    return (
        <section>
            <h3 className="mb-2 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                Gestión
            </h3>
            <div className="rounded-lg border border-border bg-surface-1">
                {/* Assignee */}
                <div className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2.5 p-3">
                    {incident.assignee ? (
                        <>
                            <UserAvatar
                                initials={incident.assignee.initials}
                                size={32}
                                isPrimary
                            />
                            <div className="min-w-0">
                                <div className="truncate text-sm font-semibold text-fg-1">
                                    {incident.assignee.name}
                                </div>
                                <div className="text-2xs text-fg-3">
                                    Responsable actual
                                </div>
                            </div>
                            <AssigneeMenu label="Reasignar" variant="link" />
                        </>
                    ) : (
                        <>
                            <UserAvatar size={32} isEmpty />
                            <div className="min-w-0">
                                <div className="text-sm text-fg-2 italic">
                                    Sin asignar
                                </div>
                            </div>
                            <AssigneeMenu label="Asignarme" variant="button" />
                        </>
                    )}
                </div>

                {/* Actions */}
                <div className="border-t border-border p-3">
                    {isTerminal ? (
                        <Button
                            variant="default"
                            className="w-full justify-center"
                            onClick={() => void reopen()}
                            disabled={pending === 'reopen'}
                        >
                            {pending === 'reopen' ? (
                                <Loader2 size={13} className="animate-spin" />
                            ) : (
                                <RefreshCw size={13} />
                            )}
                            Reabrir incidente
                        </Button>
                    ) : (
                        <Button
                            variant="default"
                            className="w-full justify-center"
                            onClick={() => setResolveOpen(true)}
                            disabled={pending === 'resolve'}
                        >
                            {pending === 'resolve' ? (
                                <Loader2 size={13} className="animate-spin" />
                            ) : null}
                            Resolver incidente
                        </Button>
                    )}

                    {!isTerminal && (
                        <div className="mt-2 flex flex-wrap gap-x-3.5 gap-y-1">
                            <button
                                type="button"
                                onClick={() => void acknowledge()}
                                disabled={pending === 'acknowledge'}
                                className={quietButton}
                            >
                                {pending === 'acknowledge' ? (
                                    <Loader2
                                        size={12}
                                        className="animate-spin"
                                    />
                                ) : (
                                    <Check size={12} />
                                )}
                                Atender (ACK)
                            </button>
                            {claimedByOther ? (
                                <span
                                    className="inline-flex items-center gap-1.5 py-1 text-xs font-medium text-fg-3"
                                    title={`Tomado por ${incident.claimedBy?.name}`}
                                >
                                    <Hand size={12} />
                                    Tomado por {incident.claimedBy?.name}
                                </span>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() =>
                                        void (claimedByMe ? release() : claim())
                                    }
                                    disabled={
                                        pending === 'claim' ||
                                        pending === 'release'
                                    }
                                    className={quietButton}
                                >
                                    {pending === 'claim' ||
                                    pending === 'release' ? (
                                        <Loader2
                                            size={12}
                                            className="animate-spin"
                                        />
                                    ) : (
                                        <Hand size={12} />
                                    )}
                                    {claimedByMe ? 'Soltar' : 'Tomar'}
                                </button>
                            )}
                            <button
                                type="button"
                                onClick={() => void escalate()}
                                disabled={pending === 'escalate'}
                                className={quietButton}
                            >
                                {pending === 'escalate' ? (
                                    <Loader2
                                        size={12}
                                        className="animate-spin"
                                    />
                                ) : (
                                    <TriangleAlert size={12} />
                                )}
                                Escalar
                            </button>
                            <button
                                type="button"
                                onClick={() => void discard()}
                                disabled={pending === 'discard'}
                                className={quietButton}
                            >
                                {pending === 'discard' ? (
                                    <Loader2
                                        size={12}
                                        className="animate-spin"
                                    />
                                ) : (
                                    <X size={12} />
                                )}
                                Descartar
                            </button>
                        </div>
                    )}
                </div>
            </div>

            <ResolveDialog open={resolveOpen} onOpenChange={setResolveOpen} />
        </section>
    );
}
