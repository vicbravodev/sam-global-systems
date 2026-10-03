import { cn } from '@/lib/utils';
import type { InboxDensity } from '@/types/sam';

const DENSITY_OPTS: { value: InboxDensity; label: string }[] = [
    { value: 'compact', label: 'C' },
    { value: 'comfortable', label: 'M' },
    { value: 'relaxed', label: 'R' },
];

export function DensityToggle({
    density,
    setDensity,
}: {
    density: InboxDensity;
    setDensity: (d: InboxDensity) => void;
}) {
    // En móvil la bandeja usa tarjetas, no filas.
    return (
        <div className="hidden shrink-0 items-center gap-1 py-1.5 sm:flex">
            {DENSITY_OPTS.map((d) => (
                <button
                    key={d.value}
                    type="button"
                    onClick={() => setDensity(d.value)}
                    className={cn(
                        'h-6 w-6 rounded-sm text-3xs font-semibold transition-colors',
                        density === d.value
                            ? 'bg-surface-3 text-fg-1'
                            : 'text-fg-3 hover:text-fg-2',
                    )}
                    title={d.value}
                >
                    {d.label}
                </button>
            ))}
        </div>
    );
}
