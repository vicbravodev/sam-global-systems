import type { LucideIcon } from 'lucide-react';
import { RefreshCw } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { cn } from '@/lib/utils';

interface ListPageProps {
    title: string;
    description?: string;
    /** Meta junto al título (conteos). */
    meta?: ReactNode;
    /** Acciones a la derecha; "Refrescar" se añade al final si hay `onRefresh`. */
    actions?: ReactNode;
    onRefresh?: () => void;
    refreshing?: boolean;
    /** Franja de pulso (`PulseStrip`) y avisos bajo el encabezado. */
    pulse?: ReactNode;
    /** Barra de filtros. */
    filters?: ReactNode;
    /** Pie fijo (normalmente `ListFooter`). */
    footer?: ReactNode;
    /** Cuerpo: la tabla o lo que desplaza. */
    children: ReactNode;
}

/**
 * Estructura de toda página de lista: encabezado fijo, pulso, filtros,
 * cuerpo y pie. El shell es `h-dvh overflow-hidden`; el cuerpo decide su
 * propio scroll.
 */
export function ListPage({
    title,
    description,
    meta,
    actions,
    onRefresh,
    refreshing = false,
    pulse,
    filters,
    footer,
    children,
}: ListPageProps) {
    const hasActions = actions != null || onRefresh !== undefined;

    return (
        <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
            <PageHeader
                title={title}
                description={description}
                meta={meta}
                actions={
                    hasActions ? (
                        <>
                            {actions}
                            {onRefresh && (
                                <RefreshButton
                                    onClick={onRefresh}
                                    refreshing={refreshing}
                                />
                            )}
                        </>
                    ) : undefined
                }
                className="shrink-0 border-b border-border bg-surface-1 px-5 py-3"
            />
            {pulse}
            {filters}
            {children}
            {footer}
        </div>
    );
}

/** El botón "Refrescar" estándar de las listas. */
export function RefreshButton({
    onClick,
    refreshing,
}: {
    onClick: () => void;
    refreshing: boolean;
}) {
    return (
        <Button
            variant="ghost"
            size="sm"
            onClick={onClick}
            disabled={refreshing}
        >
            <RefreshCw size={13} className={cn(refreshing && 'animate-spin')} />
            Refrescar
        </Button>
    );
}

interface ListEmptyStateProps {
    icon: LucideIcon;
    /** Hay filtros aplicados: el vacío es "sin resultados", no "sin datos". */
    filtered: boolean;
    title: string;
    description: string;
    filteredTitle?: string;
    filteredDescription: string;
}

/** Estado vacío de una lista: distingue "sin datos" de "sin resultados". */
export function ListEmptyState({
    icon,
    filtered,
    title,
    description,
    filteredTitle = 'Sin resultados',
    filteredDescription,
}: ListEmptyStateProps) {
    return (
        <EmptyState
            className="min-h-0 flex-1"
            icon={icon}
            title={filtered ? filteredTitle : title}
            description={filtered ? filteredDescription : description}
        />
    );
}
