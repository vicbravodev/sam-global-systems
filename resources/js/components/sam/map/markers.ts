import { ASSET_STATUS } from '@/lib/labels';
import { TONE_VAR } from '@/lib/tone';
import type { AssetMarker, AssetStatusValue } from '@/types/assets';

/**
 * DOM builders for map markers. Styles live in app.css (`.sam-map-*`); here
 * we only set the state the CSS reads (data attributes and `--unit`), so a
 * marker can be updated in place instead of being rebuilt.
 */

/** Marker color of a unit status (same tone as its badge). */
export function statusColor(status: AssetStatusValue): string {
    return TONE_VAR[ASSET_STATUS[status].tone];
}

/** Higher is more urgent: sorts lists and picks a cluster's headline status. */
export const STATUS_URGENCY: Record<AssetStatusValue, number> = {
    critical: 5,
    alert: 4,
    maintenance: 3,
    offline: 2,
    inactive: 1,
    active: 0,
};

const MUTED: ReadonlySet<AssetStatusValue> = new Set(['offline', 'inactive']);
const ALERTING: ReadonlySet<AssetStatusValue> = new Set(['alert', 'critical']);

// Below this speed a unit is drawn parked even if it reports a heading.
export const MOVING_MIN_KPH = 3;

const ARROW_SVG =
    '<svg class="sam-map-unit__arrow" viewBox="0 0 24 24" aria-hidden="true">' +
    '<path fill="currentColor" d="M12 2.5 19.5 20.5 12 16.2 4.5 20.5Z"/></svg>';

export interface UnitMarkerState {
    status: AssetStatusValue;
    /** Degrees, 0 = north; the arrow shows only while moving. */
    heading: number | null;
    moving: boolean;
    label: string | null;
    ariaLabel: string;
    selected?: boolean;
    emphasis?: boolean;
}

function setFlag(el: HTMLElement, name: string, on: boolean): void {
    if (on) {
        el.setAttribute(`data-${name}`, '');
    } else {
        el.removeAttribute(`data-${name}`);
    }
}

/** Cheap equality key: rebuild nothing when nothing visible changed. */
export function unitSignature(state: UnitMarkerState): string {
    return [
        state.status,
        state.moving && state.heading !== null ? Math.round(state.heading) : '',
        state.label ?? '',
        state.selected ? 1 : 0,
        state.emphasis ? 1 : 0,
        state.ariaLabel,
    ].join('|');
}

export function updateUnitMarker(
    el: HTMLElement,
    state: UnitMarkerState,
): void {
    el.style.setProperty('--unit', statusColor(state.status));
    setFlag(el, 'muted', MUTED.has(state.status));
    setFlag(el, 'alert', ALERTING.has(state.status));
    setFlag(el, 'selected', state.selected === true);
    setFlag(el, 'emphasis', state.emphasis === true);
    el.setAttribute('aria-label', state.ariaLabel);
    // Stacking: the selection on top, then the most urgent, so a critical
    // unit is never hidden under a healthy neighbour's label.
    el.style.zIndex = state.selected
        ? '100'
        : String(STATUS_URGENCY[state.status] * 10);

    const body = el.querySelector<HTMLElement>('.sam-map-unit__body');
    const showArrow =
        state.moving && state.heading !== null && !MUTED.has(state.status);

    if (body !== null) {
        const hasArrow = body.querySelector('.sam-map-unit__arrow') !== null;

        if (showArrow && !hasArrow) {
            body.innerHTML = ARROW_SVG;
        } else if (!showArrow && (hasArrow || body.childElementCount === 0)) {
            body.innerHTML = '<span class="sam-map-unit__dot"></span>';
        }

        const arrow = body.querySelector<SVGElement>('.sam-map-unit__arrow');

        if (arrow !== null && state.heading !== null) {
            arrow.style.transform = `rotate(${state.heading}deg)`;
        }
    }

    const label = el.querySelector<HTMLElement>('.sam-map-unit__label');

    if (label !== null) {
        label.textContent = state.label ?? '';
        label.hidden = state.label === null;
    }
}

export function createUnitMarker(
    state: UnitMarkerState,
    interactive: boolean,
): HTMLElement {
    const el = document.createElement(interactive ? 'button' : 'div');

    if (el instanceof HTMLButtonElement) {
        el.type = 'button';
    } else {
        el.setAttribute('role', 'img');
    }

    el.className = 'sam-map-unit';
    el.innerHTML =
        '<span class="sam-map-unit__body"></span>' +
        '<span class="sam-map-unit__label"></span>';

    if (!interactive) {
        el.style.cursor = 'default';
    }

    updateUnitMarker(el, state);

    return el;
}

/**
 * Conic-gradient ring with each status's share of a cluster, most urgent
 * first (clockwise from 12 o'clock).
 */
function statusMix(statuses: AssetStatusValue[]): string {
    const counts = new Map<AssetStatusValue, number>();
    statuses.forEach((s) => counts.set(s, (counts.get(s) ?? 0) + 1));

    const ordered = [...counts.entries()].sort(
        ([a], [b]) => STATUS_URGENCY[b] - STATUS_URGENCY[a],
    );

    if (ordered.length === 1) {
        return statusColor(ordered[0][0]);
    }

    let start = 0;
    const stops = ordered.map(([status, count]) => {
        const end = start + (count / statuses.length) * 360;
        const stop = `${statusColor(status)} ${start}deg ${end}deg`;
        start = end;

        return stop;
    });

    return `conic-gradient(${stops.join(', ')})`;
}

function clusterSize(count: number): number {
    return count < 10 ? 30 : count < 50 ? 36 : 42;
}

export function clusterSignature(statuses: AssetStatusValue[]): string {
    return [...statuses].sort().join(',');
}

export function updateClusterMarker(
    el: HTMLElement,
    statuses: AssetStatusValue[],
    ariaLabel: string,
): void {
    el.style.setProperty('--mix', statusMix(statuses));
    el.style.setProperty('--size', `${clusterSize(statuses.length)}px`);
    el.setAttribute('aria-label', ariaLabel);
    el.title = ariaLabel;

    const count = el.querySelector<HTMLElement>('.sam-map-cluster__count');

    if (count !== null) {
        count.textContent = String(statuses.length);
    }
}

export function createClusterMarker(
    statuses: AssetStatusValue[],
    ariaLabel: string,
): HTMLButtonElement {
    const el = document.createElement('button');
    el.type = 'button';
    el.className = 'sam-map-cluster';
    el.innerHTML = '<span class="sam-map-cluster__count"></span>';
    updateClusterMarker(el, statuses, ariaLabel);

    return el;
}

/** Location pin for events and incidents (no heading, no status). */
export function createPinMarker(
    color: string,
    ariaLabel?: string,
): HTMLElement {
    const el = document.createElement('div');
    el.className = 'sam-map-pin';
    el.style.setProperty('--unit', color);
    el.innerHTML = '<span class="sam-map-pin__core"></span>';

    if (ariaLabel) {
        el.setAttribute('role', 'img');
        el.setAttribute('aria-label', ariaLabel);
    }

    return el;
}

const CARDINALS = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'];

export function cardinal(heading: number): string {
    return CARDINALS[Math.round((((heading % 360) + 360) % 360) / 45) % 8];
}

export function isMoving(asset: AssetMarker): boolean {
    if (asset.moving !== undefined && asset.moving !== null) {
        return asset.moving;
    }

    return (asset.speed ?? 0) >= MOVING_MIN_KPH;
}

/** "42 km/h · NE", "Detenido" or "En movimiento", for tooltips and lists. */
export function speedLine(asset: AssetMarker): string {
    if (!isMoving(asset)) {
        return 'Detenido';
    }

    const speed = asset.speed !== null ? `${Math.round(asset.speed)} km/h` : '';
    const heading = asset.heading !== null ? cardinal(asset.heading) : '';

    return [speed, heading].filter(Boolean).join(' · ') || 'En movimiento';
}
