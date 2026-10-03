import { ArrowDownRight, ArrowRight, ArrowUpRight } from 'lucide-react';
import { formatNumber } from '@/lib/format';
import { TONE_TEXT } from '@/lib/tone';
import { cn } from '@/lib/utils';
import type { Better } from './metric-catalog';
import { isRatio } from './metric-catalog';

interface Props {
    value: number | null;
    previous: number | null;
    unit: string | null;
    better: Better;
    /** "vs 30 días anteriores". */
    against: string;
    className?: string;
}

/**
 * Variación frente al periodo anterior de igual duración, en palabras y con
 * color semántico: verde si mejora, ámbar si empeora, gris si no hay juicio
 * (volumen) o no hay con qué comparar. Las proporciones cambian en puntos,
 * no en % de un %.
 */
export function ChangeBadge({
    value,
    previous,
    unit,
    better,
    against,
    className,
}: Props) {
    if (value === null || previous === null) {
        return (
            <span className={cn('text-2xs text-fg-3', className)}>
                Sin datos del periodo anterior para comparar
            </span>
        );
    }

    const ratio = isRatio(unit);
    let delta: number;
    let text: string;

    if (ratio) {
        delta = Math.round((value - previous) * 100);
        text = `${delta > 0 ? '+' : ''}${delta} ${Math.abs(delta) === 1 ? 'punto' : 'puntos'}`;
    } else if (previous === 0) {
        delta = value;
        text = `${delta > 0 ? '+' : ''}${formatNumber(delta, { maximumFractionDigits: 0 })}`;
    } else {
        delta = Math.round(((value - previous) / previous) * 100);
        text = `${delta > 0 ? '+' : ''}${delta} %`;
    }

    if (delta === 0) {
        return (
            <span
                className={cn(
                    'inline-flex items-center gap-1 text-2xs text-fg-3',
                    className,
                )}
            >
                <ArrowRight className="size-3" aria-hidden="true" />
                Igual que {against}
            </span>
        );
    }

    const up = delta > 0;
    const tone =
        better === 'neutral'
            ? 'text-fg-2'
            : (better === 'up') === up
              ? TONE_TEXT.ok
              : TONE_TEXT.high;
    const Arrow = up ? ArrowUpRight : ArrowDownRight;

    return (
        <span
            className={cn(
                'inline-flex flex-wrap items-center gap-x-1 text-2xs',
                className,
            )}
        >
            <span
                className={cn(
                    'inline-flex items-center gap-0.5 font-semibold tabular-nums',
                    tone,
                )}
            >
                <Arrow className="size-3" aria-hidden="true" />
                {text}
            </span>
            <span className="text-fg-3">vs {against}</span>
        </span>
    );
}
