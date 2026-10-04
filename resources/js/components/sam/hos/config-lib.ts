import { formatNumber } from '@/lib/format';
import type {
    HosConfigurableSituation,
    HosConfigValues,
    HosLadderStep,
    HosPreview,
    HosTagOption,
} from '@/types/hos';
import { HOS_SKIPPED_LABELS } from './copy';

/** Tope de escalones (UpdateHosMonitoringConfigRequest::MAX_LADDER_STEPS). */
export const HOS_MAX_LADDER_STEPS = 8;

export interface HosLadderDraft {
    /** Llave estable de React; no viaja al servidor. */
    id: number;
    afterMinutes: string;
    channels: string[];
    escalate: boolean;
}

export interface HosConfigDraft {
    tagIds: string[];
    includedAssetIds: number[];
    excludedAssetIds: number[];
    situations: Record<HosConfigurableSituation, boolean>;
    /** Listas editadas como texto: "30, 15". */
    leadMinutes: string;
    cycleLeadHours: string;
    restCompleteNudgeMinutes: string;
    restCompleteExpireMinutes: string;
    ladder: HosLadderDraft[];
}

/** Lo que recibe UpdateHosMonitoringConfigRequest. */
export type HosConfigPayload = {
    tag_ids: string[];
    included_asset_ids: number[];
    excluded_asset_ids: number[];
    situations: Record<HosConfigurableSituation, boolean>;
    lead_minutes: number[];
    cycle_lead_hours: number[];
    rest_complete_nudge_minutes: number[];
    rest_complete_expire_minutes: number;
    ladder: {
        after_minutes: number;
        channels: string[];
        escalate: 'incident' | null;
    }[];
};

/** Errores por campo con las MISMAS llaves que el backend. */
export type HosDraftErrors = Record<string, string>;

let ladderDraftId = 0;

export function ladderDraft(step: HosLadderStep): HosLadderDraft {
    ladderDraftId += 1;

    return {
        id: ladderDraftId,
        afterMinutes: String(step.afterMinutes),
        channels: [...step.channels],
        escalate: step.escalate,
    };
}

export function toDraft(config: HosConfigValues): HosConfigDraft {
    return {
        tagIds: [...config.tagIds],
        includedAssetIds: [...config.includedAssetIds],
        excludedAssetIds: [...config.excludedAssetIds],
        situations: { ...config.situations },
        leadMinutes: config.leadMinutes.join(', '),
        cycleLeadHours: config.cycleLeadHours.join(', '),
        restCompleteNudgeMinutes: config.restCompleteNudgeMinutes.join(', '),
        restCompleteExpireMinutes: String(config.restCompleteExpireMinutes),
        ladder: config.ladder.map(ladderDraft),
    };
}

/** "30, 15" → [30, 15]; vacío → []; null si algo no es un entero ≥ 0. */
export function parseNumberList(text: string): number[] | null {
    const parts = text.split(/[\s,]+/).filter((part) => part !== '');

    if (parts.some((part) => !/^\d+$/.test(part))) {
        return null;
    }

    return parts.map(Number);
}

function parseInteger(text: string): number | null {
    const trimmed = text.trim();

    return /^\d+$/.test(trimmed) ? Number(trimmed) : null;
}

function inRange(list: number[] | null, min: number, max: number): boolean {
    return (
        list !== null &&
        list.every((n) => n >= min && n <= max) &&
        new Set(list).size === list.length
    );
}

/** Las reglas de UpdateHosMonitoringConfigRequest, antes de mandar. */
export function validateDraft(
    draft: HosConfigDraft,
    minGapMinutes: number,
): HosDraftErrors {
    const errors: HosDraftErrors = {};
    const leads = parseNumberList(draft.leadMinutes);
    const cycle = parseNumberList(draft.cycleLeadHours);
    const nudges = parseNumberList(draft.restCompleteNudgeMinutes);
    const expire = parseInteger(draft.restCompleteExpireMinutes);

    if (!inRange(leads, 1, 240) || (leads ?? []).length > 4) {
        errors.lead_minutes =
            'Escribe minutos entre 1 y 240, sin repetir, separados por comas.';
    }

    if (!inRange(cycle, 1, 69) || (cycle ?? []).length === 0) {
        errors.cycle_lead_hours =
            'Escribe horas entre 1 y 69, sin repetir, separadas por comas.';
    }

    const nudgesOk = inRange(nudges, 1, 180) && (nudges ?? []).length > 0;

    if (!nudgesOk) {
        errors.rest_complete_nudge_minutes =
            'Escribe minutos entre 1 y 180, sin repetir, separados por comas.';
    }

    if (expire === null || expire < 2 || expire > 240) {
        errors.rest_complete_expire_minutes = 'Escribe minutos entre 2 y 240.';
    } else if (nudgesOk && nudges !== null && expire <= Math.max(...nudges)) {
        errors.rest_complete_expire_minutes = `Debe ser mayor que el último recordatorio para retomar (${Math.max(...nudges)} min).`;
    }

    if (
        draft.includedAssetIds.some((id) => draft.excludedAssetIds.includes(id))
    ) {
        errors.excluded_asset_ids =
            'Una unidad no puede estar en "siempre entran" y en "nunca entran" a la vez.';
    }

    let previous: number | null = null;
    const last = draft.ladder.length - 1;

    draft.ladder.forEach((step, index) => {
        const key = `ladder.${index}`;
        const after = parseInteger(step.afterMinutes);

        if (after === null || after > 240) {
            errors[`${key}.after_minutes`] =
                'Escribe minutos enteros entre 0 y 240.';
        } else if (index === 0 && after !== 0) {
            errors[`${key}.after_minutes`] =
                'El primer escalón sale al llegar al límite: debe ser 0 min.';
        } else if (previous !== null && after - previous < minGapMinutes) {
            errors[`${key}.after_minutes`] =
                `Deja al menos ${minGapMinutes} min después del escalón anterior.`;
        }

        if (step.escalate && index !== last) {
            errors[`${key}.escalate`] =
                'El incidente sólo puede ser el último escalón.';
        }

        if (!step.escalate && step.channels.length === 0) {
            errors[`${key}.channels`] =
                'Elige al menos un canal para este escalón.';
        }

        previous = after ?? previous;
    });

    if (draft.ladder.length > HOS_MAX_LADDER_STEPS) {
        errors.ladder = `La escalera admite hasta ${HOS_MAX_LADDER_STEPS} escalones.`;
    } else if (
        !draft.ladder.some((step) => !step.escalate && step.channels.length > 0)
    ) {
        errors.ladder =
            'La escalera necesita al menos un escalón que le avise al chofer.';
    }

    return errors;
}

/** Sólo después de `validateDraft` sin errores. */
export function serializeDraft(draft: HosConfigDraft): HosConfigPayload {
    const positive = (text: string) =>
        (parseNumberList(text) ?? []).filter((n) => n > 0);

    return {
        tag_ids: draft.tagIds,
        included_asset_ids: draft.includedAssetIds,
        excluded_asset_ids: draft.excludedAssetIds,
        situations: draft.situations,
        // El 0 (llegar al límite) siempre va: es el que arranca la escalera.
        lead_minutes: [...positive(draft.leadMinutes).sort((a, b) => b - a), 0],
        cycle_lead_hours: positive(draft.cycleLeadHours).sort((a, b) => b - a),
        rest_complete_nudge_minutes: positive(
            draft.restCompleteNudgeMinutes,
        ).sort((a, b) => a - b),
        rest_complete_expire_minutes: Number(
            draft.restCompleteExpireMinutes.trim(),
        ),
        ladder: draft.ladder.map((step) => ({
            after_minutes: Number(step.afterMinutes.trim()),
            channels: step.escalate ? [] : step.channels,
            escalate: step.escalate ? 'incident' : null,
        })),
    };
}

function minutesOf(step: HosLadderDraft | undefined): number {
    return step === undefined ? 0 : Number(step.afterMinutes) || 0;
}

/** Agrega un aviso a +5 min del último, antes del incidente (que se recorre). */
export function addNoticeStep(
    ladder: HosLadderDraft[],
    channels: string[],
): HosLadderDraft[] {
    const notices = ladder.filter((step) => !step.escalate);
    const escalation = ladder.find((step) => step.escalate);
    const after = notices.length === 0 ? 0 : minutesOf(notices.at(-1)) + 5;
    const added = ladderDraft({
        afterMinutes: after,
        channels,
        escalate: false,
    });

    if (escalation === undefined) {
        return [...notices, added];
    }

    return [
        ...notices,
        added,
        {
            ...escalation,
            afterMinutes: String(Math.max(minutesOf(escalation), after + 5)),
        },
    ];
}

/** Quita un escalón; el que queda primero sale al llegar al límite (0 min). */
export function removeStep(
    ladder: HosLadderDraft[],
    index: number,
): HosLadderDraft[] {
    return ladder
        .filter((_, i) => i !== index)
        .map((step, i) => (i === 0 ? { ...step, afterMinutes: '0' } : step));
}

/** Prende (a +5 min del último aviso) o apaga el escalón final de incidente. */
export function setEscalation(
    ladder: HosLadderDraft[],
    on: boolean,
): HosLadderDraft[] {
    const notices = ladder.filter((step) => !step.escalate);

    if (!on) {
        return notices;
    }

    if (ladder.some((step) => step.escalate)) {
        return ladder;
    }

    return [
        ...notices,
        ladderDraft({
            afterMinutes: minutesOf(notices.at(-1)) + 5,
            channels: [],
            escalate: true,
        }),
    ];
}

/** "13 tractos · 43 choferes entran ahora". */
export function previewSummary(preview: HosPreview): string {
    const trucks = `${formatNumber(preview.trucks)} ${preview.trucks === 1 ? 'tracto' : 'tractos'}`;
    const drivers = `${formatNumber(preview.drivers)} ${preview.drivers === 1 ? 'chofer entra' : 'choferes entran'}`;

    return `${trucks} · ${drivers} ahora`;
}

/** "Fuera: 6 sin tracto asignado · 2 excluidos", o null si nadie queda fuera. */
export function skippedSummary(skipped: Record<string, number>): string | null {
    const parts = Object.keys(HOS_SKIPPED_LABELS)
        .filter((reason) => (skipped[reason] ?? 0) > 0)
        .map(
            (reason) =>
                `${formatNumber(skipped[reason] ?? 0)} ${HOS_SKIPPED_LABELS[reason]}`,
        );

    return parts.length === 0 ? null : `Fuera: ${parts.join(' · ')}`;
}

/** "1 tracto · 43 choferes" para el selector de etiquetas. */
export function tagMembersLabel(tag: HosTagOption): string {
    const parts = [
        tag.vehicleCount > 0
            ? `${formatNumber(tag.vehicleCount)} ${tag.vehicleCount === 1 ? 'tracto' : 'tractos'}`
            : null,
        tag.driverCount > 0
            ? `${formatNumber(tag.driverCount)} ${tag.driverCount === 1 ? 'chofer' : 'choferes'}`
            : null,
    ].filter((part): part is string => part !== null);

    return parts.length === 0 ? 'Sin miembros' : parts.join(' · ');
}
