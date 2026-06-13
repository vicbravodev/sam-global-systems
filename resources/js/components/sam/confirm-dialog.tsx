import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface ConfirmDialogProps {
    /** Cuando deja de ser null el diálogo se abre; el valor identifica el ítem. */
    open: boolean;
    title: string;
    description: string;
    confirmLabel?: string;
    cancelLabel?: string;
    /** Acción a ejecutar al confirmar. Puede ser async; se muestra spinner. */
    onConfirm: () => void | Promise<void>;
    onOpenChange: (open: boolean) => void;
}

/**
 * Diálogo único de confirmación para acciones destructivas (D-09, D-11).
 * Replica el patrón del `DeleteRoleDialog` de roles: Dialog de Radix con
 * footer Cancelar / Eliminar y guard `processing`.
 */
export function ConfirmDialog({
    open,
    title,
    description,
    confirmLabel = 'Eliminar',
    cancelLabel = 'Cancelar',
    onConfirm,
    onOpenChange,
}: ConfirmDialogProps) {
    const [submitting, setSubmitting] = useState(false);

    const confirm = async () => {
        if (submitting) {
            return;
        }

        setSubmitting(true);

        try {
            await onConfirm();
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next && !submitting) {
                    onOpenChange(false);
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                        disabled={submitting}
                    >
                        {cancelLabel}
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={confirm}
                        disabled={submitting}
                    >
                        {submitting ? (
                            <Loader2 size={14} className="animate-spin" />
                        ) : null}
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
