import {
    AlertCircle,
    AlertOctagon,
    AlertTriangle,
    Circle,
    Info,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

export type Severity = 'critical' | 'high' | 'medium' | 'low' | 'info';

type Variant = {
    label: string;
    icon: LucideIcon;
    className: string;
    solid?: boolean;
};

const VARIANTS: Record<Severity, Variant> = {
    critical: {
        label: 'Crítica',
        icon: AlertOctagon,
        solid: true,
        className: 'bg-severity-critical text-white border border-transparent',
    },
    high: {
        label: 'Alta',
        icon: AlertTriangle,
        className:
            'bg-severity-high/15 text-severity-high border border-severity-high/40',
    },
    medium: {
        label: 'Media',
        icon: AlertCircle,
        className:
            'bg-severity-medium/15 text-severity-medium border border-severity-medium/40',
    },
    low: {
        label: 'Baja',
        icon: Circle,
        className:
            'bg-severity-low/15 text-severity-low border border-severity-low/40',
    },
    info: {
        label: 'Info',
        icon: Info,
        className:
            'bg-severity-info/15 text-severity-info border border-severity-info/40',
    },
};

interface Props {
    level: Severity;
    className?: string;
}

export function SeverityBadge({ level, className }: Props) {
    const v = VARIANTS[level];
    const Icon = v.icon;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-sm px-1.5 py-1 text-[10px] font-semibold tracking-[0.02em] whitespace-nowrap',
                v.className,
                className,
            )}
        >
            <Icon className="size-3" strokeWidth={1.75} aria-hidden="true" />
            {v.label}
        </span>
    );
}
