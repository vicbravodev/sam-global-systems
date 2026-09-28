import type { ReactNode } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

interface Props {
    /** Si el rol permite la acción; con `true` el hijo se pinta tal cual. */
    allowed: boolean;
    /** Motivo mostrado al pasar el cursor, p. ej. "Tu rol no permite resolver incidentes." */
    reason: string;
    /** Control ya deshabilitado por el llamador cuando `allowed` es false. */
    children: ReactNode;
    /** Clases del envoltorio, p. ej. `w-full` para botones de ancho completo. */
    className?: string;
}

/**
 * Explica por qué una acción está deshabilitada por permisos. Un botón
 * `disabled` no emite eventos de puntero, así que el tooltip se ancla a un
 * span enfocable que lo envuelve. El servidor sigue respondiendo 403: esto
 * sólo evita ofrecer lo que el rol no puede hacer.
 */
export function PermissionTooltip({
    allowed,
    reason,
    children,
    className,
}: Props) {
    if (allowed) {
        return <>{children}</>;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    tabIndex={0}
                    aria-label={reason}
                    className={cn(
                        'inline-flex cursor-not-allowed [&>*]:pointer-events-none',
                        className,
                    )}
                >
                    {children}
                </span>
            </TooltipTrigger>
            <TooltipContent side="bottom">{reason}</TooltipContent>
        </Tooltip>
    );
}
