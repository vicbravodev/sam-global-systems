import { describe, expect, it } from 'vitest';
import type { HosConfigValues } from '@/types/hos';
import {
    addNoticeStep,
    availableChannels,
    foldServerErrors,
    ladderDraft,
    parseNumberList,
    previewSummary,
    recommendedDraft,
    removeStep,
    serializeDraft,
    setEscalation,
    skippedSummary,
    tagMembersLabel,
    toDraft,
    validateDraft,
} from './config-lib';

const DEFAULTS: HosConfigValues = {
    tagIds: [],
    includedAssetIds: [],
    excludedAssetIds: [],
    situations: {
        break_due: true,
        drive_limit: true,
        shift_limit: true,
        cycle_limit: true,
        rest_complete: true,
    },
    leadMinutes: [30, 15],
    cycleLeadHours: [5, 1],
    restCompleteNudgeMinutes: [15, 30],
    restCompleteExpireMinutes: 35,
    ladder: [
        { afterMinutes: 0, channels: ['samsara_driver_app'], escalate: false },
        {
            afterMinutes: 5,
            channels: ['samsara_driver_app', 'whatsapp'],
            escalate: false,
        },
        { afterMinutes: 10, channels: ['voice'], escalate: false },
        { afterMinutes: 15, channels: [], escalate: true },
    ],
};

const step = (afterMinutes: number, channels: string[], escalate = false) =>
    ladderDraft({ afterMinutes, channels, escalate });

describe('toDraft / serializeDraft', () => {
    it('ida y vuelta de lo recomendado con la forma que valida el backend', () => {
        expect(serializeDraft(toDraft(DEFAULTS))).toEqual({
            tag_ids: [],
            included_asset_ids: [],
            excluded_asset_ids: [],
            situations: DEFAULTS.situations,
            lead_minutes: [30, 15, 0],
            cycle_lead_hours: [5, 1],
            rest_complete_nudge_minutes: [15, 30],
            rest_complete_expire_minutes: 35,
            ladder: [
                {
                    after_minutes: 0,
                    channels: ['samsara_driver_app'],
                    escalate: null,
                },
                {
                    after_minutes: 5,
                    channels: ['samsara_driver_app', 'whatsapp'],
                    escalate: null,
                },
                { after_minutes: 10, channels: ['voice'], escalate: null },
                { after_minutes: 15, channels: [], escalate: 'incident' },
            ],
        });
    });

    it('ordena los umbrales como los guarda el backend', () => {
        const payload = serializeDraft({
            ...toDraft(DEFAULTS),
            leadMinutes: '15 ,45',
            cycleLeadHours: '1, 6',
            restCompleteNudgeMinutes: '30, 10',
        });

        expect(payload.lead_minutes).toEqual([45, 15, 0]);
        expect(payload.cycle_lead_hours).toEqual([6, 1]);
        expect(payload.rest_complete_nudge_minutes).toEqual([10, 30]);
    });
});

describe('parseNumberList', () => {
    it.each([
        ['30, 15', [30, 15]],
        ['', []],
        ['  7 ', [7]],
        ['30, -5', null],
        ['1.5', null],
        ['treinta', null],
    ])('"%s"', (text, expected) => {
        expect(parseNumberList(text)).toEqual(expected);
    });
});

describe('validateDraft', () => {
    const base = () => toDraft(DEFAULTS);

    it('lo recomendado es válido', () => {
        expect(validateDraft(base(), 2)).toEqual({});
    });

    it('rechaza umbrales negativos, en cero o repetidos con la llave del backend', () => {
        expect(
            validateDraft({ ...base(), leadMinutes: '30, -5' }, 2).lead_minutes,
        ).toBeDefined();
        expect(
            validateDraft({ ...base(), cycleLeadHours: '0' }, 2)
                .cycle_lead_hours,
        ).toBeDefined();
        expect(
            validateDraft({ ...base(), restCompleteNudgeMinutes: '15, 15' }, 2)
                .rest_complete_nudge_minutes,
        ).toBeDefined();
    });

    it('deja de recordar después del último recordatorio', () => {
        expect(
            validateDraft({ ...base(), restCompleteExpireMinutes: '30' }, 2)
                .rest_complete_expire_minutes,
        ).toBe(
            'Debe ser mayor que el último recordatorio para retomar (30 min).',
        );
    });

    it('una unidad no puede estar en las dos listas', () => {
        expect(
            validateDraft(
                { ...base(), includedAssetIds: [4], excludedAssetIds: [4] },
                2,
            ).excluded_asset_ids,
        ).toBeDefined();
    });

    it('la escalera arranca en 0, crece al menos el mínimo y el incidente va al final', () => {
        expect(
            validateDraft({ ...base(), ladder: [step(3, ['sms'])] }, 2)[
                'ladder.0.after_minutes'
            ],
        ).toBe('El primer escalón sale al llegar al límite: debe ser 0 min.');

        expect(
            validateDraft(
                { ...base(), ladder: [step(0, ['sms']), step(1, ['voice'])] },
                2,
            )['ladder.1.after_minutes'],
        ).toBe('Deja al menos 2 min después del escalón anterior.');

        const incidentInTheMiddle = validateDraft(
            {
                ...base(),
                ladder: [
                    step(0, ['sms']),
                    step(5, [], true),
                    step(10, ['voice']),
                ],
            },
            2,
        );
        expect(incidentInTheMiddle['ladder.1.escalate']).toBe(
            'El incidente sólo puede ser el último escalón.',
        );

        expect(
            validateDraft(
                { ...base(), ladder: [step(0, ['sms']), step(5, [])] },
                2,
            )['ladder.1.channels'],
        ).toBe('Elige al menos un canal para este escalón.');

        expect(
            validateDraft({ ...base(), ladder: [step(0, [], true)] }, 2).ladder,
        ).toBe(
            'La escalera necesita al menos un escalón que le avise al chofer.',
        );
    });
});

describe('addNoticeStep / setEscalation', () => {
    it('agrega el aviso antes del incidente y recorre el incidente', () => {
        const ladder = addNoticeStep(toDraft(DEFAULTS).ladder, ['sms']);

        expect(
            ladder.map((s) => [s.afterMinutes, s.channels, s.escalate]),
        ).toEqual([
            ['0', ['samsara_driver_app'], false],
            ['5', ['samsara_driver_app', 'whatsapp'], false],
            ['10', ['voice'], false],
            ['15', ['sms'], false],
            ['20', [], true],
        ]);
    });

    it('apaga y vuelve a prender el incidente a +5 del último aviso', () => {
        const off = setEscalation(toDraft(DEFAULTS).ladder, false);
        expect(off.some((s) => s.escalate)).toBe(false);

        const on = setEscalation(off, true);
        expect(on.at(-1)).toMatchObject({
            afterMinutes: '15',
            channels: [],
            escalate: true,
        });
    });
});

describe('removeStep', () => {
    it('al quitar el primero, el siguiente sale al llegar al límite', () => {
        const ladder = removeStep(toDraft(DEFAULTS).ladder, 0);

        expect(ladder.map((s) => s.afterMinutes)).toEqual(['0', '10', '15']);
        expect(validateDraft({ ...toDraft(DEFAULTS), ladder }, 2)).toEqual({});
    });

    it('quitar uno de en medio no mueve a los demás', () => {
        const ladder = removeStep(toDraft(DEFAULTS).ladder, 1);

        expect(ladder.map((s) => s.afterMinutes)).toEqual(['0', '10', '15']);
    });
});

describe('textos de la vista previa', () => {
    it('cuenta tractos y choferes con singular y plural', () => {
        const base = { skipped: {}, failed: false, hasIntegration: true };

        expect(previewSummary({ ...base, trucks: 13, drivers: 43 })).toBe(
            '13 tractos · 43 choferes entran ahora',
        );
        expect(previewSummary({ ...base, trucks: 1, drivers: 1 })).toBe(
            '1 tracto · 1 chofer entra ahora',
        );
    });

    it('dice quién queda fuera y por qué', () => {
        expect(
            skippedSummary({ no_vehicle: 6, excluded: 2, no_match: 0 }),
        ).toBe('Fuera: 6 sin tracto asignado · 2 excluidos');
        expect(skippedSummary({})).toBeNull();
    });

    it('resume lo que agrupa una etiqueta', () => {
        const tag = {
            id: '1',
            name: 'USA',
            parentId: null,
            parentName: null,
            depth: 0,
            kind: 'both' as const,
            vehicleCount: 1,
            driverCount: 43,
        };

        expect(tagMembersLabel(tag)).toBe('1 tracto · 43 choferes');
        expect(
            tagMembersLabel({ ...tag, vehicleCount: 0, driverCount: 0 }),
        ).toBe('Sin miembros');
    });
});

describe('availableChannels', () => {
    it('sólo los canales que SAM le entrega hoy al tenant, en orden', () => {
        expect(
            availableChannels([
                { value: 'samsara_driver_app', available: true },
                { value: 'whatsapp', available: false },
                { value: 'voice', available: true },
            ]),
        ).toEqual(['samsara_driver_app', 'voice']);
    });
});

describe('recommendedDraft', () => {
    const current = {
        ...toDraft(DEFAULTS),
        tagIds: ['4738197'],
        includedAssetIds: [7],
        excludedAssetIds: [9],
        situations: { ...DEFAULTS.situations, cycle_limit: false },
        leadMinutes: '45',
        ladder: [step(0, ['sms'])],
    };

    it('repone avisos y escalera pero conserva quién entra y qué se vigila', () => {
        const next = recommendedDraft(current, DEFAULTS, [
            'samsara_driver_app',
            'whatsapp',
            'voice',
        ]);

        expect(next.tagIds).toEqual(['4738197']);
        expect(next.includedAssetIds).toEqual([7]);
        expect(next.excludedAssetIds).toEqual([9]);
        expect(next.situations.cycle_limit).toBe(false);
        expect(next.leadMinutes).toBe('30, 15');
        expect(serializeDraft(next).ladder).toEqual(
            serializeDraft(toDraft(DEFAULTS)).ladder,
        );
    });

    it('quita los canales que el tenant no puede usar y los escalones que quedan vacíos', () => {
        const next = recommendedDraft(current, DEFAULTS, [
            'samsara_driver_app',
        ]);

        expect(serializeDraft(next).ladder).toEqual([
            {
                after_minutes: 0,
                channels: ['samsara_driver_app'],
                escalate: null,
            },
            {
                after_minutes: 5,
                channels: ['samsara_driver_app'],
                escalate: null,
            },
            { after_minutes: 15, channels: [], escalate: 'incident' },
        ]);
    });

    it('si el primero se queda sin canales, el siguiente sale al llegar al límite', () => {
        const next = recommendedDraft(current, DEFAULTS, ['voice']);

        expect(serializeDraft(next).ladder).toEqual([
            { after_minutes: 0, channels: ['voice'], escalate: null },
            { after_minutes: 15, channels: [], escalate: 'incident' },
        ]);
    });
});

describe('foldServerErrors', () => {
    it('junta los errores por elemento en el campo que se ve', () => {
        expect(
            foldServerErrors({
                'included_asset_ids.3': 'Una unidad ya no existe.',
                'excluded_asset_ids.0': 'Otra unidad ya no existe.',
                'tag_ids.1': 'Etiqueta inválida.',
                'ladder.2.channels.0': 'Canal inválido.',
                'ladder.1.after_minutes': 'Deja 2 min.',
                'ladder.0.escalate': 'Sólo al final.',
                'ladder.4': 'Escalón inválido.',
                'ladder.0.foo': 'Raro.',
                lead_minutes: 'Minutos.',
            }),
        ).toEqual({
            included_asset_ids: 'Una unidad ya no existe.',
            excluded_asset_ids: 'Otra unidad ya no existe.',
            tag_ids: 'Etiqueta inválida.',
            'ladder.2.channels': 'Canal inválido.',
            'ladder.1.after_minutes': 'Deja 2 min.',
            'ladder.0.escalate': 'Sólo al final.',
            ladder: 'Escalón inválido.',
            lead_minutes: 'Minutos.',
        });
    });

    it('lo que ya trae el campo no se pisa con el de otro elemento', () => {
        expect(
            foldServerErrors({
                included_asset_ids: 'Primero.',
                'included_asset_ids.0': 'Segundo.',
            }),
        ).toEqual({ included_asset_ids: 'Primero.' });
    });

    it('situations.x y las listas numéricas caen en su campo', () => {
        expect(
            foldServerErrors({
                'lead_minutes.0': 'Minutos.',
                'situations.break_due': 'Sí o no.',
            }),
        ).toEqual({ lead_minutes: 'Minutos.', situations: 'Sí o no.' });
    });
});
