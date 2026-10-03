import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

export interface DetailHeaderProps {
    /** Destino del botón de volver (normalmente el listado). */
    backHref: string;
    /** Etiqueta accesible del botón de volver ("Volver a la flota"). */
    backLabel: string;
    title: ReactNode;
    /** Avatar o icono a la izquierda del título. */
    media?: ReactNode;
    /** Fila sobre el título (severidad, estado, categoría). */
    eyebrow?: ReactNode;
    /** Chips al lado del título (estado, placa, interruptores). */
    badges?: ReactNode;
    /** Línea descriptiva bajo el título. */
    subtitle?: ReactNode;
    /** Contenido de la fila de metadatos (`sam-meta`). */
    meta?: ReactNode;
    /** Lado derecho: acciones y señales. */
    actions?: ReactNode;
}

/**
 * Encabezado de las páginas de detalle: volver, avatar, título con chips,
 * fila de metadatos y acciones a la derecha.
 */
export function DetailHeader({
    backHref,
    backLabel,
    title,
    media,
    eyebrow,
    badges,
    subtitle,
    meta,
    actions,
}: DetailHeaderProps) {
    return (
        <header className="flex flex-wrap items-start justify-between gap-4">
            <div className="flex min-w-0 items-start gap-3">
                <Button variant="ghost" size="sm" asChild className="mt-1">
                    <Link href={backHref} aria-label={backLabel}>
                        <ChevronLeft size={15} />
                    </Link>
                </Button>
                {media}
                <div className="min-w-0">
                    {eyebrow && (
                        <div className="flex flex-wrap items-center gap-2">
                            {eyebrow}
                        </div>
                    )}
                    {badges ? (
                        <div className="flex flex-wrap items-center gap-2.5">
                            <h1 className="sam-h1 truncate">{title}</h1>
                            {badges}
                        </div>
                    ) : (
                        <h1
                            className={
                                eyebrow
                                    ? 'sam-h1 mt-1 truncate'
                                    : 'sam-h1 truncate'
                            }
                        >
                            {title}
                        </h1>
                    )}
                    {subtitle && (
                        <p className="mt-0.5 text-sm text-fg-2">{subtitle}</p>
                    )}
                    {meta && (
                        <p className="sam-meta mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                            {meta}
                        </p>
                    )}
                </div>
            </div>
            {actions}
        </header>
    );
}
