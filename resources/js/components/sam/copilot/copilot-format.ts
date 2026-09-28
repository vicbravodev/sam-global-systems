import { APP_LOCALE } from '@/lib/format';

const RELATIVE = new Intl.RelativeTimeFormat(APP_LOCALE, { numeric: 'auto' });

/** "hace 5 min", "ayer"… — for timestamps inside Copilot cards. */
export function timeAgo(iso: string | null | undefined): string {
    if (!iso) {
        return 'sin registro';
    }

    const diffSeconds = Math.round((Date.parse(iso) - Date.now()) / 1000);
    const abs = Math.abs(diffSeconds);

    if (Number.isNaN(diffSeconds)) {
        return '—';
    }

    if (abs < 45) {
        return 'ahora';
    }

    if (abs < 3600) {
        return RELATIVE.format(Math.round(diffSeconds / 60), 'minute');
    }

    if (abs < 86400) {
        return RELATIVE.format(Math.round(diffSeconds / 3600), 'hour');
    }

    return RELATIVE.format(Math.round(diffSeconds / 86400), 'day');
}

/** "14:05" */
export function timeOfDay(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime())
        ? '—'
        : date.toLocaleTimeString(APP_LOCALE, {
              hour: '2-digit',
              minute: '2-digit',
          });
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
    return `US$ ${value.toLocaleString(APP_LOCALE, {
        minimumFractionDigits: value < 1 ? 4 : 2,
        maximumFractionDigits: value < 1 ? 4 : 2,
    })}`;
}
