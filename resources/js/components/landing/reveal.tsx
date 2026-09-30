import type { ReactNode } from 'react';
import { useReveal } from '@/hooks/use-reveal';
import { cn } from '@/lib/utils';

/* Scroll-reveal wrapper. Subtle fade + lift; collapses to static under
   prefers-reduced-motion (handled inside useReveal + motion-reduce classes). */
export function Reveal({
    children,
    delay = 0,
    className,
}: {
    children: ReactNode;
    delay?: number;
    className?: string;
}) {
    const { ref, visible } = useReveal<HTMLDivElement>();

    return (
        <div
            ref={ref}
            style={{ transitionDelay: `${delay}ms` }}
            className={cn(
                'transition-[opacity,transform] duration-700 ease-(--ease-out) motion-reduce:translate-y-0 motion-reduce:opacity-100 motion-reduce:transition-none',
                visible
                    ? 'translate-y-0 opacity-100'
                    : 'translate-y-4 opacity-0',
                className,
            )}
        >
            {children}
        </div>
    );
}
