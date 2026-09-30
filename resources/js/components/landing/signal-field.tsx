import { useReveal } from '@/hooks/use-reveal';
import { cn } from '@/lib/utils';

const TOTAL = 96;
const REAL = new Set([19, 58, 83]);

/**
 * Ruido vs. señal: 96 eventos llegan iguales; al entrar en pantalla SAM apaga
 * el ruido (escalonado) y deja encendidas las emergencias reales. El orden de
 * apagado es pseudoaleatorio pero determinista para que SSR e hidratación
 * coincidan. Con reduced-motion se muestra directamente el estado final.
 */
export function SignalField() {
    const { ref, visible } = useReveal<HTMLDivElement>();

    return (
        <div ref={ref}>
            <div
                className="grid grid-cols-12 place-items-center gap-y-5 sm:grid-cols-16 lg:grid-cols-24 lg:gap-y-7"
                role="img"
                aria-label="De 96 eventos recibidos, 93 se descartan con registro y 3 emergencias reales llegan a tu equipo."
            >
                {Array.from({ length: TOTAL }, (_, i) => {
                    const real = REAL.has(i);
                    const delay = ((i * 37) % TOTAL) * 12;

                    return (
                        <span
                            key={i}
                            style={{
                                transitionDelay: visible ? `${delay}ms` : '0ms',
                            }}
                            className={cn(
                                'relative size-2.5 rounded-full transition-[background-color,opacity,transform] duration-700 ease-(--ease-out) motion-reduce:transition-none sm:size-3',
                                real
                                    ? visible
                                        ? 'scale-150 bg-severity-critical'
                                        : 'bg-fg-3/45 motion-reduce:scale-150 motion-reduce:bg-severity-critical'
                                    : visible
                                      ? 'bg-fg-3/45 opacity-30'
                                      : 'bg-fg-3/45 motion-reduce:opacity-30',
                            )}
                        >
                            {real && (
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'absolute -inset-1.5 rounded-full ring-1 ring-severity-critical/50 transition-opacity delay-1000 duration-700',
                                        visible
                                            ? 'opacity-100'
                                            : 'opacity-0 motion-reduce:opacity-100',
                                    )}
                                />
                            )}
                        </span>
                    );
                })}
            </div>

            <dl className="mt-8 grid grid-cols-3 gap-4 border-t border-border pt-6">
                <Stat value="96" label="eventos recibidos" />
                <Stat value="93" label="descartados, con registro" muted />
                <Stat value="3" label="emergencias a tu equipo" critical />
            </dl>
        </div>
    );
}

function Stat({
    value,
    label,
    muted,
    critical,
}: {
    value: string;
    label: string;
    muted?: boolean;
    critical?: boolean;
}) {
    return (
        <div>
            <dt className="sr-only">{label}</dt>
            <dd
                className={cn(
                    'font-mono text-2xl font-medium tracking-tight tabular-nums',
                    critical
                        ? 'text-severity-critical'
                        : muted
                          ? 'text-fg-3'
                          : 'text-fg-1',
                )}
            >
                {value}
            </dd>
            <dd className="mt-1 text-sm text-fg-3">{label}</dd>
        </div>
    );
}
