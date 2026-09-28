import {
    Activity,
    AlertOctagon,
    Scale,
    ShieldAlert,
    Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

const ICONS: Record<string, LucideIcon> = {
    safety: ShieldAlert,
    emergency: AlertOctagon,
    compliance: Scale,
    operational: Activity,
    maintenance: Wrench,
};

const TONES: Record<string, string> = {
    safety: 'text-severity-high',
    emergency: 'text-severity-critical',
    compliance: 'text-severity-info',
    operational: 'text-fg-2',
    maintenance: 'text-severity-medium',
};

/** Category glyph so the reader tells safety from emergency before reading. */
export function EventCategoryIcon({
    code,
    size = 14,
    className,
}: {
    code: string | null | undefined;
    size?: number;
    className?: string;
}) {
    const Icon = (code && ICONS[code]) || Activity;

    return (
        <Icon
            size={size}
            strokeWidth={1.75}
            className={cn((code && TONES[code]) || 'text-fg-3', className)}
            aria-hidden="true"
        />
    );
}
