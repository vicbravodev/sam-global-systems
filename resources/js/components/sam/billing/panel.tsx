import type { ReactNode } from 'react';
import { formatCurrency } from '@/lib/format';
import { cn } from '@/lib/utils';

/** Importe con la moneda del backend ("1,234.50 MXN"). */
export function money(value: number, currency: string | null): string {
    return formatCurrency(value, currency?.toUpperCase() ?? null);
}

/**
 * Bloque de la página de facturación: título, una línea que dice qué es y
 * qué hacer aquí, y una acción opcional a la derecha.
 */
export function BillingPanel({
    title,
    description,
    action,
    children,
    className,
    bodyClassName,
}: {
    title: string;
    description?: ReactNode;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
    bodyClassName?: string;
}) {
    return (
        <section
            className={cn(
                'flex min-w-0 flex-col rounded-lg border border-border bg-surface-1',
                className,
            )}
        >
            <header className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 border-b border-border px-4 py-3">
                <div className="min-w-0">
                    <h2 className="sam-h4">{title}</h2>
                    {description && (
                        <p className="mt-0.5 text-xs text-fg-3">
                            {description}
                        </p>
                    )}
                </div>
                {action && <div className="shrink-0">{action}</div>}
            </header>
            <div className={cn('flex flex-col', bodyClassName)}>{children}</div>
        </section>
    );
}

export type BillingTone = 'ok' | 'warn' | 'critical' | 'info' | 'neutral';

const TONE_CLASS: Record<BillingTone, string> = {
    ok: 'border-severity-low/40 bg-severity-low/10 text-severity-low',
    warn: 'border-severity-medium/40 bg-severity-medium/10 text-severity-medium',
    critical:
        'border-severity-critical/40 bg-severity-critical/10 text-severity-critical',
    info: 'border-severity-info/40 bg-severity-info/10 text-severity-info',
    neutral: 'border-border bg-surface-3 text-fg-3',
};

/** Estado con color semántico y texto (nunca sólo color). */
export function BillingPill({
    tone,
    children,
    className,
}: {
    tone: BillingTone;
    children: ReactNode;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-sm border px-1.5 py-0.5 text-3xs font-semibold whitespace-nowrap',
                TONE_CLASS[tone],
                className,
            )}
        >
            {children}
        </span>
    );
}
