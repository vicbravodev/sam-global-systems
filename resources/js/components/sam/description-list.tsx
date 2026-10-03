import { createContext, useContext } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type DescriptionLayout = 'stacked' | 'inline';

const LayoutContext = createContext<DescriptionLayout>('stacked');

export interface DescriptionListProps {
    /**
     * `stacked`: etiqueta en mayúsculas sobre el valor, en rejilla de dos
     * columnas. `inline`: etiqueta a la izquierda (200px) y valor a la
     * derecha, en una sola columna.
     */
    layout?: DescriptionLayout;
    children: ReactNode;
    className?: string;
}

const LIST_CLASS: Record<DescriptionLayout, string> = {
    stacked: 'grid grid-cols-2 gap-x-4 gap-y-3',
    inline: 'flex flex-col gap-3 text-xs',
};

/** Lista de pares etiqueta/valor (`<dl>`) para fichas de detalle. */
export function DescriptionList({
    layout = 'stacked',
    children,
    className,
}: DescriptionListProps) {
    return (
        <LayoutContext value={layout}>
            <dl className={cn(LIST_CLASS[layout], className)}>{children}</dl>
        </LayoutContext>
    );
}

export interface DescriptionItemProps {
    label: ReactNode;
    children: ReactNode;
    /** Texto de ayuda bajo el valor (sólo en `inline`). */
    help?: ReactNode;
    /** Valor en monoespaciada (identificadores, placas, VIN). */
    mono?: boolean;
    className?: string;
    valueClassName?: string;
}

export function DescriptionItem({
    label,
    children,
    help,
    mono = false,
    className,
    valueClassName,
}: DescriptionItemProps) {
    const layout = useContext(LayoutContext);

    if (layout === 'inline') {
        return (
            <div
                className={cn(
                    'grid gap-1 sm:grid-cols-[200px_1fr] sm:gap-4',
                    className,
                )}
            >
                <dt className="flex flex-col gap-0.5 text-fg-3">
                    <span>{label}</span>
                </dt>
                <dd
                    className={cn(
                        'flex min-w-0 flex-col gap-1 text-fg-1',
                        mono && 'font-mono',
                        valueClassName,
                    )}
                >
                    {children}
                    {help ? (
                        <span className="text-2xs leading-relaxed text-fg-3">
                            {help}
                        </span>
                    ) : null}
                </dd>
            </div>
        );
    }

    return (
        <div className={cn('flex flex-col gap-0.5', className)}>
            <dt className="text-2xs tracking-caps text-fg-3 uppercase">
                {label}
            </dt>
            <dd
                className={cn(
                    'text-sm text-fg-1',
                    mono && 'font-mono',
                    valueClassName,
                )}
            >
                {children}
            </dd>
        </div>
    );
}
