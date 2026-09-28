import type { Severity } from '@/components/sam/severity-badge';

/** Maps a catalog severity code (event_severities.code) to the badge level. */
export function toSeverity(code: string | null | undefined): Severity {
    return code === 'critical' ||
        code === 'high' ||
        code === 'medium' ||
        code === 'low'
        ? code
        : 'info';
}

export const SEVERITY_DOT: Record<Severity, string> = {
    critical: 'bg-severity-critical',
    high: 'bg-severity-high',
    medium: 'bg-severity-medium',
    low: 'bg-severity-low',
    info: 'bg-severity-info',
};

export const SEVERITY_BORDER: Record<Severity, string> = {
    critical: 'border-l-severity-critical',
    high: 'border-l-severity-high',
    medium: 'border-l-severity-medium',
    low: 'border-l-severity-low',
    info: 'border-l-severity-info',
};
