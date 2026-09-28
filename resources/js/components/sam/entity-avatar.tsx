import type { LucideIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

// Eight muted hues (OKLCH-ish via tailwind tokens would need per-name tokens;
// we use inline oklch so any name gets a stable, legible tint in both modes).
const HUES = [250, 160, 52, 320, 90, 200, 20, 285];

function hueFor(seed: string): number {
    let hash = 0;

    for (let i = 0; i < seed.length; i++) {
        hash = (hash * 31 + seed.charCodeAt(i)) | 0;
    }

    return HUES[Math.abs(hash) % HUES.length];
}

export function initialsOf(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) {
        return '?';
    }

    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }

    return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase();
}

interface Props {
    name: string;
    /** Pixel size. */
    size?: number;
    /** Square (vehicles) instead of round (people). */
    shape?: 'circle' | 'square';
    /** Icon instead of initials (e.g. Truck for a unit). */
    icon?: LucideIcon;
    className?: string;
}

/**
 * Deterministic tinted avatar: the same driver/unit always gets the same
 * hue, so a roster scans by color before reading names. Initials for
 * people, an icon for things.
 */
export function EntityAvatar({
    name,
    size = 28,
    shape = 'circle',
    icon: Icon,
    className,
}: Props) {
    const hue = hueFor(name);

    return (
        <span
            aria-hidden="true"
            className={cn(
                'inline-grid shrink-0 place-items-center border font-semibold select-none',
                shape === 'circle' ? 'rounded-full' : 'rounded-md',
                className,
            )}
            style={{
                width: size,
                height: size,
                fontSize: Math.max(9, Math.round(size * 0.38)),
                background: `oklch(0.5 0.09 ${hue} / 0.18)`,
                borderColor: `oklch(0.6 0.12 ${hue} / 0.45)`,
                color: `oklch(var(--avatar-l, 0.62) 0.13 ${hue})`,
            }}
        >
            {Icon ? (
                <Icon
                    style={{ width: size * 0.5, height: size * 0.5 }}
                    strokeWidth={1.75}
                />
            ) : (
                initialsOf(name)
            )}
        </span>
    );
}
