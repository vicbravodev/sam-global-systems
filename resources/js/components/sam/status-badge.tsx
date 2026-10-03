import { LoaderCircle } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { TONE_DOT, TONE_PILL } from '@/lib/tone';
import type { Tone } from '@/lib/tone';
import { cn } from '@/lib/utils';

export interface StatusBadgeProps {
    tone: Tone;
    label: ReactNode;
    /** Punto de color antes del texto. */
    dot?: boolean;
    /** El punto late (estado en curso). */
    pulse?: boolean;
    /** Ícono antes del texto; `'spinner'` para trabajo en curso. */
    icon?: LucideIcon | 'spinner';
    /**
     * `md` (por defecto) comparte geometría con `SeverityBadge`, `StatusPill`
     * y `MetaChip`; `sm` es la variante compacta de tablas densas y paneles.
     */
    size?: 'sm' | 'md';
    title?: string;
    className?: string;
}

/**
 * Estado con color semántico y texto (nunca sólo color). Hermano genérico de
 * `SeverityBadge` (severidad) y `StatusPill` (estado de incidente); los
 * mapas de dominio dan `{ label, tone }` y esto lo pinta:
 * `<StatusBadge dot {...ASSET_STATUS[status]} />`.
 */
export function StatusBadge({
    tone,
    label,
    dot = false,
    pulse = false,
    icon: Icon,
    size = 'md',
    title,
    className,
}: StatusBadgeProps) {
    return (
        <span
            title={title}
            className={cn(
                'inline-flex items-center rounded-sm border px-1.5 text-3xs font-semibold whitespace-nowrap',
                size === 'md' ? 'gap-1.5 py-1 tracking-label' : 'gap-1 py-0.5',
                TONE_PILL[tone],
                className,
            )}
        >
            {Icon === 'spinner' ? (
                <LoaderCircle
                    className="size-3 motion-safe:animate-spin"
                    aria-hidden="true"
                />
            ) : Icon ? (
                <Icon
                    className="size-3"
                    strokeWidth={1.75}
                    aria-hidden="true"
                />
            ) : dot ? (
                <span
                    className={cn(
                        'size-1.5 rounded-full',
                        TONE_DOT[tone],
                        pulse && 'motion-safe:animate-pulse',
                    )}
                    aria-hidden="true"
                />
            ) : null}
            {label}
        </span>
    );
}
