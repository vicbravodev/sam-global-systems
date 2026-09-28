import type { ReactNode } from 'react';
import { PageHeader } from '@/components/ui/page-header';
import { cn } from '@/lib/utils';

interface SettingsPageProps {
    title: string;
    /** Una línea: qué es esta sección y qué se hace aquí. */
    description?: string;
    meta?: ReactNode;
    actions?: ReactNode;
    /** `narrow` para formularios personales, `wide` para listas y rejillas. */
    width?: 'narrow' | 'wide';
    children: ReactNode;
}

/**
 * Estructura de toda página de Ajustes: encabezado fijo y cuerpo que
 * desplaza (el shell es `h-dvh overflow-hidden`, sin `overflow-y-auto` no
 * habría scroll).
 */
export function SettingsPage({
    title,
    description,
    meta,
    actions,
    width = 'narrow',
    children,
}: SettingsPageProps) {
    return (
        <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
            <PageHeader
                title={title}
                description={description}
                meta={meta}
                actions={actions}
                className="shrink-0 border-b border-border bg-surface-1 px-5 py-3"
            />
            <div className="min-h-0 flex-1 overflow-y-auto">
                <div
                    className={cn(
                        'flex flex-col gap-6 px-5 py-5 pb-24',
                        width === 'narrow' ? 'max-w-3xl' : 'max-w-6xl',
                    )}
                >
                    {children}
                </div>
            </div>
        </div>
    );
}

interface SettingsSectionProps {
    title: string;
    description?: string;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
    /** Tono de peligro para acciones irreversibles. */
    tone?: 'default' | 'danger';
}

/**
 * Bloque titulado dentro de una página de Ajustes: título + una línea de
 * contexto y, debajo, su contenido (normalmente un `FormCard` con `Field`s).
 */
export function SettingsSection({
    title,
    description,
    actions,
    children,
    className,
    tone = 'default',
}: SettingsSectionProps) {
    return (
        <section className={cn('flex flex-col gap-3', className)}>
            <div className="flex flex-wrap items-end justify-between gap-2">
                <div className="min-w-0">
                    <h2
                        className={cn(
                            'sam-h4',
                            tone === 'danger' && 'text-severity-critical',
                        )}
                    >
                        {title}
                    </h2>
                    {description ? (
                        <p className="mt-0.5 text-xs text-fg-3">
                            {description}
                        </p>
                    ) : null}
                </div>
                {actions ? (
                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                        {actions}
                    </div>
                ) : null}
            </div>
            {children}
        </section>
    );
}

/** Pie de un FormCard con la acción de guardar alineada a la derecha. */
export function FormActions({
    children,
    hint,
}: {
    children: ReactNode;
    hint?: ReactNode;
}) {
    return (
        <div className="-mx-5 -mb-5 flex flex-wrap items-center justify-between gap-2 rounded-b-lg border-t border-border bg-surface-2 px-5 py-3">
            <span className="text-2xs text-fg-3">{hint}</span>
            <div className="flex items-center gap-2">{children}</div>
        </div>
    );
}
