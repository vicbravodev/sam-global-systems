import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Field, FormCard } from '@/components/sam/field';
import {
    availableChannels,
    foldServerErrors,
    previewSummary,
    recommendedDraft,
    serializeDraft,
    skippedSummary,
    toDraft,
    validateDraft,
} from '@/components/sam/hos/config-lib';
import type {
    HosConfigDraft,
    HosDraftErrors,
} from '@/components/sam/hos/config-lib';
import { HOS_SITUATIONS, HOS_THROTTLED } from '@/components/sam/hos/copy';
import { HosAssetPicker } from '@/components/sam/hos/hos-asset-picker';
import { HosLadderEditor } from '@/components/sam/hos/hos-ladder-editor';
import { HosTagPicker } from '@/components/sam/hos/hos-tag-picker';
import { useHosPreview } from '@/components/sam/hos/use-hos-preview';
import type { HosPreviewState } from '@/components/sam/hos/use-hos-preview';
import { useHosTags } from '@/components/sam/hos/use-hos-tags';
import {
    FormActions,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { putJson } from '@/lib/sam-fetch';
import { submit } from '@/lib/submit';
import { cn } from '@/lib/utils';
import tenantConfigRoutes from '@/routes/tenant-config';
import type { HosConfigForm } from '@/types/hos';
import { CONFIG_SUBMIT } from './shared';

/**
 * Monitoreo HOS (EE. UU.): quién entra (con vista previa), qué se vigila,
 * cuándo se avisa y cómo se le insiste al chofer. Se guarda completo en
 * `hos.monitoring` por su propio endpoint (UpdateHosMonitoringConfigRequest).
 */
export function HosSection({ form }: { form: HosConfigForm }) {
    const teamSlug = usePage().props.currentTeam?.slug ?? null;
    const [draft, setDraft] = useState<HosConfigDraft>(() =>
        toDraft(form.config),
    );
    const [errors, setErrors] = useState<HosDraftErrors>({});
    const [saving, setSaving] = useState(false);
    const [previewAttempt, setPreviewAttempt] = useState(0);
    const disabled = !form.canManage || saving;

    const tags = useHosTags(
        teamSlug === null ? null : tenantConfigRoutes.hos.tags.url(teamSlug),
    );
    const preview = useHosPreview(
        teamSlug === null || !form.hasIntegration
            ? null
            : tenantConfigRoutes.hos.preview.url(teamSlug),
        {
            tagIds: draft.tagIds,
            includedAssetIds: draft.includedAssetIds,
            excludedAssetIds: draft.excludedAssetIds,
        },
        600,
        previewAttempt,
    );

    const update = (patch: Partial<HosConfigDraft>) =>
        setDraft((current) => ({ ...current, ...patch }));

    const applyRecommended = () => {
        // Sólo avisos y escalera: quién entra y qué se vigila se quedan como están.
        setDraft((current) =>
            recommendedDraft(
                current,
                form.defaults,
                availableChannels(form.channels),
            ),
        );
        setErrors({});
    };

    const save = async () => {
        if (teamSlug === null || saving) {
            return;
        }

        const found = validateDraft(draft, form.minGapMinutes);
        setErrors(found);

        if (Object.keys(found).length > 0) {
            return;
        }

        setSaving(true);
        const result = await submit(
            putJson(
                tenantConfigRoutes.hos.update.url(teamSlug),
                serializeDraft(draft),
            ),
            'Monitoreo HOS guardado.',
            { ...CONFIG_SUBMIT, only: ['hos', 'versions'] },
        );
        setErrors(foldServerErrors(result.fieldErrors));
        setSaving(false);
    };

    return (
        <>
            <SettingsSection
                title="Quién entra al monitoreo"
                description="Sólo choferes que van en un tracto vigilado. Elige por etiquetas de Samsara y agrega o quita unidades a mano."
            >
                <FormCard>
                    <Field
                        label="Etiquetas de Samsara"
                        help="Entran los choferes con la etiqueta o que manejan un tracto que la tiene."
                        error={errors.tag_ids}
                    >
                        <HosTagPicker
                            tags={tags}
                            selected={draft.tagIds}
                            disabled={disabled}
                            onChange={(tagIds) => update({ tagIds })}
                        />
                    </Field>
                    <Field
                        label="Unidades que siempre entran"
                        help="Aunque no tengan la etiqueta."
                        htmlFor="hos-included"
                        error={errors.included_asset_ids}
                    >
                        <HosAssetPicker
                            id="hos-included"
                            assets={form.assets}
                            selected={draft.includedAssetIds}
                            taken={draft.excludedAssetIds}
                            disabled={disabled}
                            placeholder="Agregar unidad…"
                            noteFor={(asset) =>
                                asset.monitored ? null : 'no vigilada: no entra'
                            }
                            onChange={(includedAssetIds) =>
                                update({ includedAssetIds })
                            }
                        />
                    </Field>
                    <Field
                        label="Unidades que nunca entran"
                        help="Ganan sobre las etiquetas y las unidades agregadas."
                        htmlFor="hos-excluded"
                        error={errors.excluded_asset_ids}
                    >
                        <HosAssetPicker
                            id="hos-excluded"
                            assets={form.assets}
                            selected={draft.excludedAssetIds}
                            taken={draft.includedAssetIds}
                            disabled={disabled}
                            placeholder="Agregar unidad…"
                            onChange={(excludedAssetIds) =>
                                update({ excludedAssetIds })
                            }
                        />
                    </Field>
                    <PreviewLine
                        preview={preview}
                        hasIntegration={form.hasIntegration}
                        onRetry={() => setPreviewAttempt((n) => n + 1)}
                    />
                </FormCard>
            </SettingsSection>

            <SettingsSection
                title="Qué vigila SAM"
                description="Las infracciones que ya marca Samsara siempre se vigilan y abren incidente."
            >
                <FormCard>
                    {HOS_SITUATIONS.map((situation) => (
                        <Field
                            key={situation.key}
                            label={situation.label}
                            help={situation.help}
                            htmlFor={`hos-situation-${situation.key}`}
                        >
                            <div className="flex items-center gap-2.5">
                                <Switch
                                    id={`hos-situation-${situation.key}`}
                                    checked={draft.situations[situation.key]}
                                    disabled={disabled}
                                    onCheckedChange={(on) =>
                                        update({
                                            situations: {
                                                ...draft.situations,
                                                [situation.key]: on,
                                            },
                                        })
                                    }
                                />
                                <span className="text-sm text-fg-2">
                                    {draft.situations[situation.key]
                                        ? 'Activado'
                                        : 'Desactivado'}
                                </span>
                            </div>
                        </Field>
                    ))}
                </FormCard>
            </SettingsSection>

            <SettingsSection
                title="Cuándo avisa"
                description="Los avisos previos sólo informan; al llegar al límite empieza la escalera de insistencia."
            >
                <FormCard>
                    <Field
                        label="Avisos antes del límite"
                        help="Minutos antes de que se acabe el descanso, el manejo o el turno. Separa con comas."
                        htmlFor="hos-lead"
                        error={errors.lead_minutes}
                    >
                        <Input
                            id="hos-lead"
                            inputMode="numeric"
                            value={draft.leadMinutes}
                            disabled={disabled}
                            onChange={(event) =>
                                update({ leadMinutes: event.target.value })
                            }
                            className="h-8 w-40"
                        />
                    </Field>
                    <Field
                        label="Avisos del ciclo de 70 h"
                        help="Horas que le quedan en el ciclo. Separa con comas."
                        htmlFor="hos-cycle"
                        error={errors.cycle_lead_hours}
                    >
                        <Input
                            id="hos-cycle"
                            inputMode="numeric"
                            value={draft.cycleLeadHours}
                            disabled={disabled}
                            onChange={(event) =>
                                update({ cycleLeadHours: event.target.value })
                            }
                            className="h-8 w-40"
                        />
                    </Field>
                    <Field
                        label="Recordatorios para retomar"
                        help="Minutos después de cumplir su descanso, si todavía no arranca. Separa con comas."
                        htmlFor="hos-rest"
                        error={errors.rest_complete_nudge_minutes}
                    >
                        <Input
                            id="hos-rest"
                            inputMode="numeric"
                            value={draft.restCompleteNudgeMinutes}
                            disabled={disabled}
                            onChange={(event) =>
                                update({
                                    restCompleteNudgeMinutes:
                                        event.target.value,
                                })
                            }
                            className="h-8 w-40"
                        />
                    </Field>
                    <Field
                        label="Dejar de recordar a los"
                        help="Minutos después de cumplir su descanso. Debe ser mayor que el último recordatorio."
                        htmlFor="hos-rest-expire"
                        error={errors.rest_complete_expire_minutes}
                    >
                        <span className="flex items-center gap-2">
                            <Input
                                id="hos-rest-expire"
                                type="number"
                                min="2"
                                value={draft.restCompleteExpireMinutes}
                                disabled={disabled}
                                onChange={(event) =>
                                    update({
                                        restCompleteExpireMinutes:
                                            event.target.value,
                                    })
                                }
                                className="h-8 w-20 tabular-nums"
                            />
                            <span className="text-xs text-fg-3">min</span>
                        </span>
                    </Field>
                </FormCard>
            </SettingsSection>

            <SettingsSection
                title="Escalera de insistencia"
                description="Qué hace SAM, y por dónde, mientras el chofer no corrige. Se pausa en cuanto se detiene."
            >
                <FormCard>
                    <HosLadderEditor
                        ladder={draft.ladder}
                        channels={form.channels}
                        errors={errors}
                        disabled={disabled}
                        onChange={(ladder) => update({ ladder })}
                    />
                    {form.canManage ? (
                        <FormActions hint="Los cambios aplican desde el siguiente minuto.">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                disabled={saving}
                                onClick={applyRecommended}
                            >
                                Avisos y escalera recomendados
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                disabled={saving}
                                onClick={() => void save()}
                            >
                                {saving ? (
                                    <Spinner className="size-3.5" />
                                ) : null}
                                Guardar
                            </Button>
                        </FormActions>
                    ) : null}
                </FormCard>
            </SettingsSection>
        </>
    );
}

function RetryButton({ onRetry }: { onRetry: () => void }) {
    return (
        <Button
            type="button"
            variant="link"
            size="sm"
            className="h-auto self-start p-0 text-xs"
            onClick={onRetry}
        >
            Reintentar
        </Button>
    );
}

function PreviewLine({
    preview,
    hasIntegration,
    onRetry,
}: {
    preview: HosPreviewState;
    hasIntegration: boolean;
    onRetry: () => void;
}) {
    if (!hasIntegration || preview.status === 'idle') {
        return (
            <p className="text-xs text-fg-3">
                Conecta tu integración con Samsara para ver quién entra.
            </p>
        );
    }

    if (preview.status === 'error') {
        return (
            <div
                className="flex flex-col gap-0.5 text-xs text-fg-3"
                role="status"
            >
                <span>
                    No pudimos calcular quién entra ahora. Tu configuración se
                    puede guardar igual.
                </span>
                <RetryButton onRetry={onRetry} />
            </div>
        );
    }

    const current = preview.status === 'ready' ? preview.preview : preview.last;
    const throttled =
        preview.status === 'throttled' ? (
            <span className="text-2xs text-fg-3">{HOS_THROTTLED}</span>
        ) : null;

    if (current === null) {
        return (
            <p
                className="flex flex-col gap-0.5 text-xs text-fg-3"
                role="status"
            >
                {throttled ?? (
                    <span className="flex items-center gap-2">
                        <Spinner className="size-3.5" />
                        Calculando quién entra…
                    </span>
                )}
            </p>
        );
    }

    if (current.failed) {
        return (
            <div
                className="flex flex-col gap-0.5 text-xs text-fg-3"
                role="status"
            >
                <span>
                    Samsara no respondió. Tu configuración se puede guardar
                    igual.
                </span>
                {throttled ?? <RetryButton onRetry={onRetry} />}
            </div>
        );
    }

    const skipped = skippedSummary(current.skipped);

    return (
        <div
            role="status"
            aria-busy={preview.status !== 'ready'}
            className={cn(
                'flex flex-col gap-0.5 rounded-md border border-border bg-surface-2 px-3 py-2',
                preview.status !== 'ready' && 'opacity-60',
            )}
        >
            <span className="text-sm font-semibold text-fg-1 tabular-nums">
                {previewSummary(current)}
            </span>
            {skipped ? (
                <span className="text-2xs text-fg-3">{skipped}</span>
            ) : null}
            {throttled}
        </div>
    );
}
