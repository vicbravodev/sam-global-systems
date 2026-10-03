import { formatPercent } from '@/lib/format';

export function percentLabel(value: number | null): string {
    return value === null
        ? '—'
        : formatPercent(value, { alreadyPercent: true });
}

export function formatSlaClock(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }

    const clamped = Math.max(seconds, 0);
    const minutes = Math.floor(clamped / 60);
    const rest = clamped % 60;

    return `${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
}
