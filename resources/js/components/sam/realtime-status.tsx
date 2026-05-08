import { cn } from '@/lib/utils';

export type RealtimeState = 'ok' | 'warn' | 'down';

const VARIANTS: Record<RealtimeState, { label: string; dotClass: string }> = {
    ok: { label: 'Conectado', dotClass: 'bg-health-ok' },
    warn: { label: 'Reconectando…', dotClass: 'bg-health-warn' },
    down: { label: 'Desconectado', dotClass: 'bg-health-down' },
};

interface Props {
    state?: RealtimeState;
    className?: string;
    label?: string;
}

export function RealtimeStatus({ state = 'ok', className, label }: Props) {
    const v = VARIANTS[state];
    const text = label ?? v.label;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-2 rounded-full border border-border bg-surface-2 py-1 pr-2.5 pl-2 text-xs font-medium',
                className,
            )}
            role="status"
            aria-live="polite"
        >
            <span className="relative grid place-items-center">
                <span
                    className={cn('size-2 rounded-full', v.dotClass)}
                    aria-hidden="true"
                />
                {state === 'ok' && (
                    <span
                        className={cn(
                            'absolute inset-0 -m-1 rounded-full opacity-30 motion-safe:animate-[sam-pulse_1.6s_ease-out_infinite]',
                            v.dotClass,
                        )}
                        aria-hidden="true"
                    />
                )}
            </span>
            <span className="text-fg-1">{text}</span>
        </span>
    );
}
