import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

export interface ConfirmDialogProps {
    open: boolean;
    title: string;
    description: ReactNode;
    confirmLabel?: string;
    cancelLabel?: string;
    /** Acción a ejecutar al confirmar. Puede ser async; se muestra spinner. */
    onConfirm: () => void | Promise<void>;
    onOpenChange: (open: boolean) => void;
    /** `default` para confirmaciones no destructivas (p.ej. generar factura). */
    tone?: 'destructive' | 'default';
    /**
     * Petición en curso controlada desde fuera (p.ej. `router.visit` con
     * `onStart`/`onFinish`), cuando `onConfirm` no devuelve una promesa.
     */
    processing?: boolean;
    /** `data-test` del botón de confirmar, para pruebas de navegador. */
    confirmTestId?: string;
}

/**
 * Diálogo único de confirmación: Dialog de Radix con footer Cancelar /
 * Confirmar y guarda contra doble envío mientras la acción está en curso.
 */
export function ConfirmDialog({
    open,
    title,
    description,
    confirmLabel = 'Eliminar',
    cancelLabel = 'Cancelar',
    onConfirm,
    onOpenChange,
    tone = 'destructive',
    processing = false,
    confirmTestId,
}: ConfirmDialogProps) {
    const [submitting, setSubmitting] = useState(false);
    const busy = submitting || processing;

    const confirm = async () => {
        if (busy) {
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
                if (!next && !busy) {
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
                        disabled={busy}
                    >
                        {cancelLabel}
                    </Button>
                    <Button
                        variant={
                            tone === 'destructive' ? 'destructive' : 'default'
                        }
                        onClick={confirm}
                        disabled={busy}
                        data-test={confirmTestId}
                    >
                        {busy ? <Spinner className="size-3.5" /> : null}
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
