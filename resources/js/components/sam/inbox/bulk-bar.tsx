import { Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';

export interface BulkBarProps {
    count: number;
    pending: string | null;
    canManage: boolean;
    canResolve: boolean;
    onAssign: () => void;
    onEscalate: () => void;
    onDiscard: () => void;
    onClear: () => void;
}

export function BulkBar({
    count,
    pending,
    canManage,
    canResolve,
    onAssign,
    onEscalate,
    onDiscard,
    onClear,
}: BulkBarProps) {
    const busy = pending !== null;

    return (
        <div className="flex shrink-0 items-center gap-2.5 border-b border-border bg-primary/18 px-5 py-2">
            <span className="text-xs font-semibold text-primary">
                {count} seleccionados
            </span>
            {canManage && (
                <>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={onAssign}
                        disabled={busy}
                    >
                        {pending === 'assign' ? (
                            <Loader2 size={12} className="animate-spin" />
                        ) : null}
                        Asignarme
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={onEscalate}
                        disabled={busy}
                    >
                        {pending === 'escalate' ? (
                            <Loader2 size={12} className="animate-spin" />
                        ) : null}
                        Escalar
                    </Button>
                </>
            )}
            {canResolve && (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={onDiscard}
                    disabled={busy}
                >
                    {pending === 'discard' ? (
                        <Loader2 size={12} className="animate-spin" />
                    ) : null}
                    Descartar
                </Button>
            )}
            {!canManage && !canResolve && (
                <span className="text-xs text-fg-3">
                    Tu rol no permite acciones en lote.
                </span>
            )}
            <Button
                size="sm"
                variant="ghost"
                onClick={onClear}
                className="ml-auto"
                disabled={busy}
            >
                Deseleccionar
            </Button>
        </div>
    );
}
