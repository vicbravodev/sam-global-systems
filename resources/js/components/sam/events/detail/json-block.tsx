import { ChevronRight, Copy } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

export function JsonBlock({
    title,
    data,
}: {
    title: string;
    data: Record<string, unknown> | null;
}) {
    const json =
        data !== null && Object.keys(data).length > 0
            ? JSON.stringify(data, null, 2)
            : null;

    const copy = (e: React.MouseEvent) => {
        // El botón vive dentro del <summary>: sin esto, copiar también
        // colapsa/expande el bloque.
        e.preventDefault();
        e.stopPropagation();

        if (json !== null) {
            void navigator.clipboard.writeText(json);
            toast.success('Payload copiado al portapapeles.');
        }
    };

    return (
        <Card className="gap-0 py-0">
            <CardContent className="p-0">
                {/* Colapsado por defecto (F4.3): la evaluación/decisión es lo
                    que el operador necesita; el JSON es material de soporte. */}
                <details className="group">
                    <summary className="flex cursor-pointer items-center gap-2 px-4 py-3 select-none [&::-webkit-details-marker]:hidden">
                        <ChevronRight
                            size={14}
                            className="text-fg-3 transition-transform group-open:rotate-90"
                        />
                        <span className="flex-1 text-xs font-semibold tracking-caps text-fg-2 uppercase">
                            {title}
                        </span>
                        {json !== null ? (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={copy}
                                aria-label={`Copiar ${title}`}
                            >
                                <Copy size={12} />
                                Copiar
                            </Button>
                        ) : (
                            <span className="text-2xs text-fg-3">
                                sin datos
                            </span>
                        )}
                    </summary>
                    <div className="px-4 pb-4">
                        {json === null ? (
                            <p className="text-xs text-fg-3">
                                Este evento no trae datos en esta sección.
                            </p>
                        ) : (
                            <pre className="max-h-72 overflow-auto rounded-md bg-surface-2 p-3 font-mono text-2xs leading-relaxed text-fg-2">
                                {json}
                            </pre>
                        )}
                    </div>
                </details>
            </CardContent>
        </Card>
    );
}
