import { CHANNEL_DELIVERY } from '@/components/sam/notifications/copy';
import { formatNumber } from '@/lib/format';
import { channelLabel } from '@/lib/labels';
import { hoursMinutesLabel, relativeLabel } from '@/lib/time';
import type { Tone } from '@/lib/tone';
import type { HosClocks, HosFleetData, HosNudge } from '@/types/hos';
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

/** Mismo criterio que `HosDutyStatus::isWorking()`. */
const WORKING_STATUSES = ['driving', 'onDuty', 'yardMove'];

/** Manejando, en turno o en patio: sus relojes corren. */
export function isWorkingStatus(dutyStatus: string): boolean {
    return WORKING_STATUSES.includes(dutyStatus);
}

/**
 * Parado (fuera de turno, en litera, uso personal) un reloj en 0 es lo
 * normal: no se pinta como alarma. Sin estado conocido no se presume.
 */
function isResting(dutyStatus: string | null | undefined): boolean {
    return (
        dutyStatus !== null &&
        dutyStatus !== undefined &&
        !isWorkingStatus(dutyStatus)
    );
}

function remainingLabel(seconds: number | null, resting: boolean): string {
    if (seconds === null) {
        return 'Sin dato';
    }

    if (seconds <= 0) {
        return resting ? 'En 0' : 'Agotado';
    }

    return hoursMinutesLabel(seconds);
}

export function clockBars(
    clocks: HosClocks,
    dutyStatus: string | null = null,
): HosClockBar[] {
    const resting = isResting(dutyStatus);

    return HOS_CLOCK_KEYS.map((key) => {
        const tone = clockTone(clocks[key], HOS_WARNING_SECONDS[key]);

        return {
            key,
            label: HOS_CLOCK_LABELS[key],
            remaining: clocks[key],
            max: HOS_CLOCK_FULL_SECONDS[key],
            tone: resting && tone !== 'ok' ? 'neutral' : tone,
            valueLabel: remainingLabel(clocks[key], resting),
        };
    });
}

/** El reloj más corto de una fila de la flota. */
export function nearestLabel(row: {
    minRemainingSeconds: number | null;
    dutyStatus?: string | null;
}): string {
    return row.minRemainingSeconds === null
        ? '—'
        : remainingLabel(row.minRemainingSeconds, isResting(row.dutyStatus));
}

/** Igual que `ListHosFleet::STALE_SECONDS`. */
export const HOS_STALE_SECONDS = 180;

/**
 * Sin filas recientes pero con una lectura vieja: Samsara dejó de responder
 * (o el sondeo se detuvo), no es que nadie esté en monitoreo.
 */
export function fleetOutageLabel(
    fleet: Pick<HosFleetData, 'rows' | 'lastObservedAt'>,
): string | null {
    if (fleet.rows.length > 0 || fleet.lastObservedAt === null) {
        return null;
    }

    const age = Date.now() - Date.parse(fleet.lastObservedAt);

    return age > HOS_STALE_SECONDS * 1000
        ? `Sin lectura de Samsara desde ${relativeLabel(fleet.lastObservedAt, 'long')}`
        : null;
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

/**
 * Props que recarga cada sondeo en el detalle del chofer: sólo `hos` y sólo
 * con su pestaña abierta (al abrirla se hace una recarga parcial).
 */
export function hosReloadProps(
    hasHos: boolean,
    tab: 'resumen' | 'hos',
): string[] | null {
    return hasHos && tab === 'hos' ? ['hos'] : null;
}
