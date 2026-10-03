import { formatDateWith, formatNumber } from '@/lib/format';
import { formatClock, relativeLabel } from '@/lib/time';

/** "hace 5 minutos", "ayer"… — for timestamps inside Copilot cards. */
export function timeAgo(iso: string | null | undefined): string {
    return iso ? relativeLabel(iso, 'long') : 'sin registro';
}

/** "14:05" */
export function timeOfDay(iso: string | null | undefined): string {
    return formatClock(iso);
}

export function formatTokens(value: number): string {
    if (value >= 1_000_000) {
        return `${(value / 1_000_000).toFixed(1)} M`;
    }

    if (value >= 1_000) {
        return `${(value / 1_000).toFixed(1)} k`;
    }

    return String(value);
}

export function formatUsd(value: number): string {
    return `US$ ${formatNumber(value, {
        minimumFractionDigits: value < 1 ? 4 : 2,
        maximumFractionDigits: value < 1 ? 4 : 2,
    })}`;
}

/** "lun 29 sep, 14:05" — for timelines that span several days. */
export function dayAndTime(iso: string | null | undefined): string {
    return formatDateWith(iso, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}
