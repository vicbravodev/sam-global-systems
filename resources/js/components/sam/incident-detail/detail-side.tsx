import { Check, Loader2, RefreshCw, TriangleAlert, X } from 'lucide-react';
import { useState } from 'react';
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
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type { IncidentDetail } from '@/types/sam';
import { useIncidentActions } from './incident-actions-context';

// ---- UserAvatar ----

function UserAvatar({
    initials,
    size = 32,
    isPrimary = false,
    isEmpty = false,
}: {
    initials?: string;
    size?: number;
    isPrimary?: boolean;
    isEmpty?: boolean;
}) {
    return (
        <span
            className={cn(
                'inline-grid shrink-0 place-items-center rounded-full border border-border font-semibold',
                isPrimary
                    ? 'bg-primary text-primary-foreground'
                    : isEmpty
                      ? 'bg-surface-3 text-fg-3'
                      : 'bg-surface-3 text-fg-2',
            )}
            style={{
                width: size,
                height: size,
                fontSize: Math.max(9, size * 0.4),
            }}
        >
            {isEmpty ? '?' : initials}
        </span>
    );
}

// ---- AssigneeMenu (member picker) ----

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

// ---- RiskBar ----

function RiskBar({ value }: { value: number }) {
    const color =
        value > 70
            ? 'text-severity-critical'
            : value > 40
              ? 'text-severity-high'
              : 'text-severity-low';
    const bgColor =
        value > 70
            ? 'bg-severity-critical'
            : value > 40
              ? 'bg-severity-high'
              : 'bg-severity-low';

    return (
        <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
            <span className={cn('font-mono text-2xs font-semibold', color)}>
                {value}
            </span>
            <span className="h-1 w-12 shrink-0 overflow-hidden rounded-full bg-surface-3">
                <span
                    className={cn('block h-full', bgColor)}
                    style={{ width: `${value}%` }}
                />
            </span>
        </span>
    );
}

// ---- SectionTitle ----

function SectionTitle({ children }: { children: React.ReactNode }) {
    return (
        <h3 className="mb-2 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
            {children}
        </h3>
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
                        <select
                            value={code}
                            onChange={(e) => setCode(e.target.value)}
                            className="rounded-md border border-border bg-surface-1 px-2.5 py-1.5 text-sm text-fg-1 outline-none"
                        >
                            {RESOLUTION_OPTIONS.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </select>
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

// ---- DetailSide ----

interface DetailSideProps {
    incident: IncidentDetail;
}

export function DetailSide({ incident }: DetailSideProps) {
    const ctx = incident.operationalContext;
    const { reopen, acknowledge, escalate, discard, pending } =
        useIncidentActions();
    const [resolveOpen, setResolveOpen] = useState(false);

    const isTerminal = ['resolved', 'closed', 'discarded'].includes(
        incident.status,
    );

    return (
        <div className="flex flex-col gap-5">
            {/* Assignee */}
            <section>
                <SectionTitle>Asignación</SectionTitle>
                <div
                    className="grid items-center gap-2.5 rounded-md border border-border bg-surface-2 p-3"
                    style={{
                        gridTemplateColumns: 'auto minmax(0,1fr) auto',
                    }}
                >
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
            </section>

            {/* Actions */}
            <section>
                <SectionTitle>Acciones</SectionTitle>
                {isTerminal ? (
                    <Button
                        variant="default"
                        className="mb-2 w-full justify-center"
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
                        className="mb-2 w-full justify-center"
                        onClick={() => setResolveOpen(true)}
                        disabled={pending === 'resolve'}
                    >
                        {pending === 'resolve' ? (
                            <Loader2 size={13} className="animate-spin" />
                        ) : null}
                        Resolver incidente
                    </Button>
                )}
                <div className="mt-1 flex flex-wrap gap-x-3.5 gap-y-1">
                    <button
                        type="button"
                        onClick={() => void acknowledge()}
                        disabled={isTerminal || pending === 'acknowledge'}
                        className="inline-flex cursor-pointer items-center gap-1.5 border-none bg-transparent py-1 text-xs font-medium text-fg-2 hover:text-fg-1 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        {pending === 'acknowledge' ? (
                            <Loader2 size={12} className="animate-spin" />
                        ) : (
                            <Check size={12} />
                        )}
                        Atender (ACK)
                    </button>
                    <button
                        type="button"
                        onClick={() => void escalate()}
                        disabled={isTerminal || pending === 'escalate'}
                        className="inline-flex cursor-pointer items-center gap-1.5 border-none bg-transparent py-1 text-xs font-medium text-fg-2 hover:text-fg-1 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        {pending === 'escalate' ? (
                            <Loader2 size={12} className="animate-spin" />
                        ) : (
                            <TriangleAlert size={12} />
                        )}
                        Escalar
                    </button>
                    <button
                        type="button"
                        onClick={() => void discard()}
                        disabled={isTerminal || pending === 'discard'}
                        className="inline-flex cursor-pointer items-center gap-1.5 border-none bg-transparent py-1 text-xs font-medium text-fg-2 hover:text-fg-1 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        {pending === 'discard' ? (
                            <Loader2 size={12} className="animate-spin" />
                        ) : (
                            <X size={12} />
                        )}
                        Descartar
                    </button>
                    <button
                        type="button"
                        onClick={() => void reopen()}
                        disabled={!isTerminal || pending === 'reopen'}
                        className="inline-flex cursor-pointer items-center gap-1.5 border-none bg-transparent py-1 text-xs font-medium text-fg-2 hover:text-fg-1 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <RefreshCw size={12} />
                        Reabrir
                    </button>
                </div>
            </section>

            {/* Operational context */}
            <section>
                <SectionTitle>Contexto operativo</SectionTitle>
                <div className="flex flex-col overflow-hidden rounded-md border border-border bg-surface-2">
                    {[
                        { key: 'Clima', value: ctx.weather },
                        { key: 'Tráfico', value: ctx.traffic },
                        {
                            key: 'Riesgo cond.',
                            value: <RiskBar value={ctx.driverRisk} />,
                        },
                        { key: 'Geocerca', value: ctx.geofenceStatus },
                        { key: 'H. conducción', value: ctx.drivingHours },
                    ].map(({ key, value }) => (
                        <div
                            key={key}
                            className="grid items-center gap-3 border-b border-border px-3 py-2 text-xs last:border-b-0"
                            style={{
                                gridTemplateColumns: 'minmax(88px,auto) 1fr',
                            }}
                        >
                            <span className="text-2xs font-medium whitespace-nowrap text-fg-3">
                                {key}
                            </span>
                            <span className="justify-self-end text-right text-fg-1">
                                {value}
                            </span>
                        </div>
                    ))}
                </div>
            </section>

            <ResolveDialog open={resolveOpen} onOpenChange={setResolveOpen} />
        </div>
    );
}
