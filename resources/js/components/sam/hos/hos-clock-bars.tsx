import { Meter } from '@/components/sam/meter';
import { TONE_DOT, TONE_TEXT } from '@/lib/tone';
import { cn } from '@/lib/utils';
import type { HosClocks } from '@/types/hos';
import { clockBars } from './lib';

export interface HosClockBarsProps {
    clocks: HosClocks;
}

/** Descanso, manejo, turno y ciclo: lo que queda en cada reloj. */
export function HosClockBars({ clocks }: HosClockBarsProps) {
    return (
        <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {clockBars(clocks).map((bar) => (
                <div key={bar.key} className="flex flex-col gap-1.5">
                    <dt className="text-xs text-fg-2">{bar.label}</dt>
                    <dd className="flex flex-col gap-1.5">
                        <span
                            className={cn(
                                'text-sm font-semibold tabular-nums',
                                TONE_TEXT[bar.tone],
                            )}
                        >
                            {bar.valueLabel}
                        </span>
                        <Meter
                            value={Math.max(0, bar.remaining ?? 0)}
                            max={bar.max}
                            label={`${bar.label}: ${bar.valueLabel}`}
                            toneClassName={TONE_DOT[bar.tone]}
                            minPercent={bar.remaining === null ? 0 : 2}
                        />
                    </dd>
                </div>
            ))}
        </dl>
    );
}
