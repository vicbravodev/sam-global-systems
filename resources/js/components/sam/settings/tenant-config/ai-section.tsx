import { useState } from 'react';
import { Field, FormCard } from '@/components/sam/field';
import {
    FormActions,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { putJson } from '@/lib/sam-fetch';
import { cn } from '@/lib/utils';
import { submit, useTeamBase } from './shared';
import type { AiProfile, Option } from './types';

/** Qué significa cada nivel para quien opera (clave = AutomationLevel). */
const LEVEL_HELP: Record<string, string> = {
    conservative:
        'La IA sólo recomienda y pide revisión humana ante cualquier duda.',
    assisted:
        'La IA propone y una persona confirma las decisiones importantes.',
    semi_automatic:
        'La IA resuelve lo rutinario y deja a las personas los casos dudosos.',
    highly_automated:
        'La IA decide en la mayoría de los casos; las personas supervisan.',
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
    const base = useTeamBase();
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
        if (base === null) {
            return;
        }

        setSaving(true);
        await submit(
            putJson(`${base}/ai-profile`, {
                ...form,
                description: form.description === '' ? null : form.description,
            }),
            'Respuesta de la IA guardada.',
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
                  TenantAIProfile que hoy llega al pipeline (ver
                  app/Domains/AI/Data/TenantAIProfileData.php).
                */}
                <Field
                    label="Nivel de autonomía"
                    help="Se aplica a las evaluaciones nuevas. Puedes cambiarlo cuando quieras."
                >
                    <div
                        role="radiogroup"
                        aria-label="Nivel de autonomía"
                        className="flex flex-col gap-2"
                    >
                        {levels.map((level) => {
                            const selected =
                                form.automation_level === level.value;

                            return (
                                <button
                                    key={level.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={selected}
                                    disabled={!canManage}
                                    onClick={() =>
                                        set('automation_level', level.value)
                                    }
                                    className={cn(
                                        'flex items-start gap-3 rounded-md border px-3 py-2.5 text-left transition-colors ease-(--ease-out) disabled:cursor-not-allowed motion-safe:duration-[--motion-fast]',
                                        selected
                                            ? 'border-primary bg-primary/5'
                                            : 'border-border hover:bg-surface-2',
                                    )}
                                >
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
                                    <span className="min-w-0">
                                        <span className="flex flex-wrap items-center gap-2 text-sm font-medium text-fg-1">
                                            {level.label}
                                            {level.value ===
                                            RECOMMENDED_LEVEL ? (
                                                <span className="rounded-sm bg-surface-3 px-1.5 py-0.5 text-2xs font-medium text-fg-2">
                                                    Recomendado
                                                </span>
                                            ) : null}
                                        </span>
                                        {LEVEL_HELP[level.value] ? (
                                            <span className="block text-xs text-fg-3">
                                                {LEVEL_HELP[level.value]}
                                            </span>
                                        ) : null}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
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
