import { CHANNEL_DELIVERY } from '@/components/sam/notifications/copy';
import { formatNumber } from '@/lib/format';
import { channelLabel } from '@/lib/labels';
import { hoursMinutesLabel } from '@/lib/time';
import type { Tone } from '@/lib/tone';
import type { HosClocks, HosFleetRow, HosNudge } from '@/types/hos';
import { HOS_CLOCK_LABELS, HOS_NOTICE_LABELS } from './copy';

export const HOS_CLOCK_KEYS = ['break', 'drive', 'shift', 'cycle'] as const;

/** Valor de cada reloj en reposo (US Interstate Property, 70 h / 8 días). */
export const HOS_CLOCK_FULL_SECONDS: Record<keyof HosClocks, number> = {
    break: 8 * 3600,
    drive: 11 * 3600,
    shift: 14 * 3600,
    cycle: 70 * 3600,
};

/** Bajo esto el reloj se pinta en advertencia (primer aviso recomendado). */
export const HOS_WARNING_SECONDS: Record<keyof HosClocks, number> = {
    break: 30 * 60,
    drive: 30 * 60,
    shift: 30 * 60,
    cycle: 5 * 3600,
};

export interface HosClockBar {
    key: keyof HosClocks;
    label: string;
    remaining: number | null;
    max: number;
    tone: Tone;
    valueLabel: string;
}

export function clockTone(
    remaining: number | null,
    warningSeconds: number,
): Tone {
    if (remaining === null) {
        return 'neutral';
    }

    if (remaining <= 0) {
        return 'critical';
    }

    return remaining <= warningSeconds ? 'warn' : 'ok';
}

function remainingLabel(seconds: number | null): string {
    if (seconds === null) {
        return 'Sin dato';
    }

    return seconds <= 0 ? 'Agotado' : hoursMinutesLabel(seconds);
}

export function clockBars(clocks: HosClocks): HosClockBar[] {
    return HOS_CLOCK_KEYS.map((key) => ({
        key,
        label: HOS_CLOCK_LABELS[key],
        remaining: clocks[key],
        max: HOS_CLOCK_FULL_SECONDS[key],
        tone: clockTone(clocks[key], HOS_WARNING_SECONDS[key]),
        valueLabel: remainingLabel(clocks[key]),
    }));
}

/** El reloj más corto de una fila de la flota. */
export function nearestLabel(
    row: Pick<HosFleetRow, 'minRemainingSeconds'>,
): string {
    return row.minRemainingSeconds === null
        ? '—'
        : remainingLabel(row.minRemainingSeconds);
}

/** Cuántos avisos salieron de verdad (no el escalón en que va el episodio). */
export function nudgesLabel(count: number): string {
    if (count === 0) {
        return 'Sin avisos enviados';
    }

    return count === 1
        ? '1 aviso enviado'
        : `${formatNumber(count)} avisos enviados`;
}

export function nudgeTitle(nudge: HosNudge): string {
    return nudge.notice === null
        ? 'Aviso'
        : (HOS_NOTICE_LABELS[nudge.notice] ?? 'Aviso');
}

/** "App de Samsara: entregado" por cada entrega del aviso. */
export function deliveryLines(
    nudge: HosNudge,
): { key: string; text: string; tone: Tone }[] {
    return nudge.deliveries.map((delivery, index) => {
        const state = CHANNEL_DELIVERY[delivery.status];

        return {
            key: `${delivery.channel ?? 'canal'}-${index}`,
            text: `${channelLabel(delivery.channel)}: ${state?.label ?? delivery.status}`,
            tone: state?.tone ?? 'neutral',
        };
    });
}

/** Pestaña inicial del detalle del chofer (`?pestana=hos` desde la flota). */
export function driverTabFromUrl(url: string): 'resumen' | 'hos' {
    const query = url.includes('?') ? url.slice(url.indexOf('?') + 1) : '';

    return new URLSearchParams(query).get('pestana') === 'hos'
        ? 'hos'
        : 'resumen';
}
