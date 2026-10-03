import { Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export interface ReadOnlyNoticeProps {
    /** Por qué no se puede editar. */
    children?: ReactNode;
    className?: string;
}

/** Aviso de sólo lectura para quien puede ver algo pero no cambiarlo. */
export function ReadOnlyNotice({
    children = 'Puedes consultar esta configuración, pero sólo un administrador puede cambiarla.',
    className,
}: ReadOnlyNoticeProps) {
    return (
        <div
            className={cn(
                'flex items-start gap-2 rounded-md border border-border bg-surface-2 px-3 py-2 text-xs leading-relaxed text-fg-2',
                className,
            )}
        >
            <Lock className="mt-0.5 size-3.5 shrink-0 text-fg-3" aria-hidden />
            <p>{children}</p>
        </div>
    );
}
