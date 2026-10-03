import type { Severity } from '@/components/sam/severity-badge';
import type { Tone } from '@/lib/tone';

/*
 * Clases por nivel de severidad para quien no pinta un `SeverityBadge`
 * (puntos, bordes de fila, texto). Única copia: no redeclarar en features.
 */

/** Maps a catalog severity code (event_severities.code) to the badge level. */
export function toSeverity(code: string | null | undefined): Severity {
    return code === 'critical' ||
        code === 'high' ||
        code === 'medium' ||
        code === 'low'
        ? code
        : 'info';
}

export const SEVERITY_TEXT: Record<Severity, string> = {
    critical: 'text-severity-critical',
    high: 'text-severity-high',
    medium: 'text-severity-medium',
    low: 'text-severity-low',
    info: 'text-severity-info',
};

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

/** Tono equivalente a cada severidad, para pintar con `StatusBadge`. */
export const SEVERITY_TONE: Record<Severity, Tone> = {
    critical: 'critical',
    high: 'high',
    medium: 'warn',
    low: 'ok',
    info: 'info',
};
