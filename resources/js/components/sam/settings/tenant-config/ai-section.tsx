import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Field, FormCard } from '@/components/sam/field';
import { RadioCard, RadioCardGroup } from '@/components/sam/radio-card-group';
import {
    FormActions,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { putJson } from '@/lib/sam-fetch';
import { submit } from '@/lib/submit';
import { cn } from '@/lib/utils';
import tenantConfigRoutes from '@/routes/tenant-config';
import { CONFIG_SUBMIT } from './shared';
import type { AiProfile, Option } from './types';

/**
 * Qué hace cada nivel (clave = AutomationLevel). Los porcentajes son los
 * umbrales de config('ai.automation_levels.human_review_threshold').
 */
const LEVEL_HELP: Record<string, string> = {
    conservative: 'Una persona revisa todas las decisiones de la IA.',
    assisted: 'Pide revisión humana si la IA tiene menos de 50 % de confianza.',
    semi_automatic:
        'Pide revisión humana si la IA tiene menos de 40 % de confianza.',
    highly_automated:
        'Pide revisión humana sólo si la IA tiene menos de 30 % de confianza.',
};

const RECOMMENDED_LEVEL = 'assisted';

export function AiSection({
    profile,
    levels,
    canManage,
}: {
    profile: AiProfile;
    levels: Option[];
    canManage: boolean;
}) {
    const teamSlug = usePage().props.currentTeam?.slug ?? null;
    // risk_tolerance / false_positive_tolerance / media_strategy no tienen
    // control aquí y no se mandan: UpdateTenantAIProfileRequest los acepta
    // como `sometimes` y el controlador conserva el valor persistido.
    const [form, setForm] = useState({
        profile_code: profile.profileCode ?? 'custom',
        name: profile.name ?? 'Perfil de la empresa',
        description: profile.description ?? '',
        automation_level: profile.automationLevel ?? RECOMMENDED_LEVEL,
    });
    const [saving, setSaving] = useState(false);

    const set = (key: keyof typeof form, value: string) =>
        setForm((prev) => ({ ...prev, [key]: value }));

    const save = async () => {
        if (teamSlug === null) {
            return;
        }

        setSaving(true);
        await submit(
            putJson(tenantConfigRoutes.aiProfile.update.url(teamSlug), {
                ...form,
                description: form.description === '' ? null : form.description,
            }),
            'Respuesta de la IA guardada.',
            CONFIG_SUBMIT,
        );
        setSaving(false);
    };

    return (
        <SettingsSection
            title="Autonomía de la IA"
            description="La IA evalúa cada evento y recomienda qué hacer. Aquí decides cuánto actúa por su cuenta."
        >
            <FormCard>
                {/*
                  Sólo se expone automation_level: es el único campo de
                  TenantAIProfile que hoy llega al pipeline (prompt de la IA
                  y umbral de revisión humana del motor de decisiones, ver
                  ResolveTenantDecisionRules).
                */}
                <Field
                    label="Nivel de autonomía"
                    help="Se aplica a las decisiones nuevas. Pánico, colisión y vuelco siempre abren incidente al instante."
                >
                    <RadioCardGroup
                        label="Nivel de autonomía"
                        className="flex flex-col gap-2"
                    >
                        {levels.map((level) => {
                            const selected =
                                form.automation_level === level.value;

                            return (
                                <RadioCard
                                    key={level.value}
                                    selected={selected}
                                    disabled={!canManage}
                                    onSelect={() =>
                                        set('automation_level', level.value)
                                    }
                                    className="py-2.5"
                                    leading={
                                        <span
                                            className={cn(
                                                'mt-0.5 grid size-4 shrink-0 place-items-center rounded-full border',
                                                selected
                                                    ? 'border-primary'
                                                    : 'border-fg-3',
                                            )}
                                            aria-hidden
                                        >
                                            {selected ? (
                                                <span className="size-2 rounded-full bg-primary" />
                                            ) : null}
                                        </span>
                                    }
                                    label={
                                        <>
                                            {level.label}
                                            {level.value ===
                                            RECOMMENDED_LEVEL ? (
                                                <span className="rounded-sm bg-surface-3 px-1.5 py-0.5 text-2xs font-medium text-fg-2">
                                                    Recomendado
                                                </span>
                                            ) : null}
                                        </>
                                    }
                                    description={LEVEL_HELP[level.value]}
                                />
                            );
                        })}
                    </RadioCardGroup>
                </Field>

                <Field
                    label="Nombre del perfil"
                    help="Sólo para identificarlo en el historial de cambios."
                    htmlFor="tc-ai-name"
                >
                    <Input
                        id="tc-ai-name"
                        value={form.name}
                        disabled={!canManage}
                        onChange={(e) => set('name', e.target.value)}
                    />
                </Field>

                <Field
                    label="Notas"
                    help="Opcional: por qué elegiste este nivel, para quien lo revise después."
                    htmlFor="tc-ai-description"
                >
                    <Input
                        id="tc-ai-description"
                        value={form.description}
                        disabled={!canManage}
                        placeholder="Ej. Revisamos todo mientras calibramos las reglas"
                        onChange={(e) => set('description', e.target.value)}
                    />
                </Field>

                {canManage ? (
                    <FormActions>
                        <Button
                            size="sm"
                            onClick={() => void save()}
                            disabled={saving}
                        >
                            Guardar cambios
                        </Button>
                    </FormActions>
                ) : null}
            </FormCard>
        </SettingsSection>
    );
}
