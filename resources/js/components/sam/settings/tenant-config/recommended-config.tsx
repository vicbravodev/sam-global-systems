import { CheckCircle2, Loader2, Sparkles } from 'lucide-react';
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
import { postJson } from '@/lib/sam-fetch';
import { submit, useTeamBase } from './shared';

const INCLUDES = [
    'Protocolo de botón de pánico: todo pánico abre un incidente y se verifica con una llamada.',
    'Video e imágenes pedidos automáticamente en cada evento crítico.',
    'Alertas cuando una unidad deja de reportar o se detiene fuera de sus zonas.',
    'Escalamiento de incidentes críticos a responsables y administradores.',
    'Avisos por correo, SMS o llamada sólo a partir de gravedad media.',
];

/**
 * Tarjeta "Configuración recomendada": explica qué crea el paquete de SAM y
 * pide confirmación antes de aplicarlo. Nunca pisa lo ya configurado.
 */
export function RecommendedConfigCard() {
    const base = useTeamBase();
    const [open, setOpen] = useState(false);
    const [applying, setApplying] = useState(false);

    const apply = async () => {
        if (base === null || applying) {
            return;
        }

        setApplying(true);
        const result = await submit(
            postJson(`${base}/apply-sam-defaults`, {}),
            'Configuración recomendada aplicada.',
        );
        setApplying(false);

        if (result.ok) {
            setOpen(false);
        }
    };

    return (
        <div className="flex max-w-3xl flex-col gap-3 rounded-lg border border-primary/30 bg-primary/5 p-4 sm:flex-row sm:items-center">
            <div className="grid size-9 shrink-0 place-items-center rounded-md bg-primary/15 text-primary">
                <Sparkles className="size-4" aria-hidden />
            </div>
            <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-fg-1">
                    ¿Empiezas de cero? Usa la configuración recomendada
                </p>
                <p className="text-xs text-fg-2">
                    SAM completa lo que te falte con valores probados en otras
                    flotas. Lo que ya configuraste no se toca.
                </p>
            </div>
            <Button
                size="sm"
                variant="outline"
                className="shrink-0"
                onClick={() => setOpen(true)}
                data-test="apply-sam-defaults"
            >
                Revisar y aplicar
            </Button>

            <Dialog
                open={open}
                onOpenChange={(next) => !applying && setOpen(next)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Aplicar la configuración recomendada
                        </DialogTitle>
                        <DialogDescription>
                            Sólo se crea lo que todavía no tienes. Ningún ajuste
                            que ya hayas cambiado se modifica, y podrás
                            ajustarlo todo después.
                        </DialogDescription>
                    </DialogHeader>
                    <ul className="flex flex-col gap-2 text-sm text-fg-2">
                        {INCLUDES.map((item) => (
                            <li key={item} className="flex items-start gap-2">
                                <CheckCircle2
                                    className="mt-0.5 size-4 shrink-0 text-severity-low"
                                    aria-hidden
                                />
                                {item}
                            </li>
                        ))}
                    </ul>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setOpen(false)}
                            disabled={applying}
                        >
                            Cancelar
                        </Button>
                        <Button
                            onClick={() => void apply()}
                            disabled={applying}
                        >
                            {applying ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : null}
                            Aplicar recomendada
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
