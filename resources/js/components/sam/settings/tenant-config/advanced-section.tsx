import { usePage } from '@inertiajs/react';
import { History, SlidersHorizontal } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Field, FormCard } from '@/components/sam/field';
import { RelativeTime } from '@/components/sam/relative-time';
import {
    FormActions,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { formatDateTime } from '@/lib/format';
import { putJson } from '@/lib/sam-fetch';
import { submit } from '@/lib/submit';
import { minutesSince } from '@/lib/time';
import tenantConfigRoutes from '@/routes/tenant-config';
import {
    DEDICATED_KEYS,
    describeSetting,
    formatSettingValue,
    SETTING_TOPICS,
    unitLabel,
} from './settings-catalog';
import type { SettingTopic } from './settings-catalog';
import { CONFIG_SUBMIT } from './shared';
import type { SettingRow, VersionRow } from './types';

export function AdvancedSection({
    settings,
    versions,
    canManage,
}: {
    settings: SettingRow[];
    versions: VersionRow[];
    canManage: boolean;
}) {
    const others = settings.filter((s) => !DEDICATED_KEYS.has(s.key));
    const byTopic = new Map<SettingTopic, SettingRow[]>();

    for (const setting of others) {
        const topic = describeSetting(setting).topic;
        byTopic.set(topic, [...(byTopic.get(topic) ?? []), setting]);
    }

    return (
        <>
            {others.length === 0 ? (
                <SettingsSection
                    title="Ajustes finos"
                    description="Valores de detalle para video, llamadas de verificación y monitoreo."
                >
                    <FormCard>
                        <EmptyState
                            className="py-6"
                            icon={SlidersHorizontal}
                            title="Todo con los valores de SAM"
                            description="Aquí aparecerán los ajustes finos cuando apliques la configuración recomendada o SAM los active para tu cuenta."
                        />
                    </FormCard>
                </SettingsSection>
            ) : (
                SETTING_TOPICS.filter((topic) => byTopic.has(topic.key)).map(
                    (topic) => (
                        <TopicBlock
                            key={topic.key}
                            title={topic.title}
                            description={topic.description}
                            settings={byTopic.get(topic.key) ?? []}
                            canManage={canManage}
                        />
                    ),
                )
            )}

            <VersionsBlock versions={versions} />
        </>
    );
}

// ---- Ajustes finos por tema ----

function isEditable(setting: SettingRow): boolean {
    return (
        (setting.valueType === 'number' && typeof setting.value === 'number') ||
        (setting.valueType === 'boolean' &&
            typeof setting.value === 'boolean') ||
        (setting.valueType === 'string' && typeof setting.value === 'string')
    );
}

function TopicBlock({
    title,
    description,
    settings,
    canManage,
}: {
    title: string;
    description: string;
    settings: SettingRow[];
    canManage: boolean;
}) {
    const teamSlug = usePage().props.currentTeam?.slug ?? null;
    const initial = useMemo(
        () =>
            Object.fromEntries(
                settings.map((s) => [
                    s.key,
                    typeof s.value === 'boolean' ? s.value : String(s.value),
                ]),
            ) as Record<string, string | boolean>,
        [settings],
    );
    const [values, setValues] = useState(initial);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const changed = settings.filter(
        (s) => isEditable(s) && values[s.key] !== initial[s.key],
    );

    const save = async () => {
        if (teamSlug === null || saving || changed.length === 0) {
            return;
        }

        const nextErrors: Record<string, string> = {};
        const payload = changed.map((setting) => {
            const meta = describeSetting(setting);
            let value: unknown = values[setting.key];

            if (setting.valueType === 'number') {
                const parsed = Number(String(value).replace(',', '.'));

                if (
                    String(value).trim() === '' ||
                    !Number.isFinite(parsed) ||
                    parsed < (meta.min ?? 0)
                ) {
                    nextErrors[setting.key] =
                        `Escribe un número mayor o igual a ${meta.min ?? 0}.`;
                }

                value = parsed;
            }

            return {
                setting_key: setting.key,
                setting_group: setting.group ?? 'operational',
                value_type: setting.valueType,
                value,
            };
        });

        setErrors(nextErrors);

        if (Object.keys(nextErrors).length > 0) {
            return;
        }

        setSaving(true);
        const result = await submit(
            putJson(tenantConfigRoutes.settings.update.url(teamSlug), {
                settings: payload,
            }),
            `${title}: cambios guardados.`,
            CONFIG_SUBMIT,
        );

        if (!result.ok) {
            const mapped: Record<string, string> = {};

            changed.forEach((setting, index) => {
                const message = result.fieldErrors[`settings.${index}.value`];

                if (message) {
                    mapped[setting.key] = message;
                }
            });
            setErrors(mapped);
        }

        setSaving(false);
    };

    return (
        <SettingsSection title={title} description={description}>
            <FormCard>
                {settings.map((setting) => {
                    const meta = describeSetting(setting);
                    const id = `adv-${setting.id}`;
                    const editable = canManage && isEditable(setting);
                    const value = values[setting.key];

                    return (
                        <Field
                            error={errors[setting.key]}
                            key={setting.id}
                            label={meta.label}
                            help={meta.help || undefined}
                            htmlFor={editable ? id : undefined}
                        >
                            {!editable ? (
                                <span
                                    className="text-sm text-fg-1"
                                    title={setting.key}
                                >
                                    {formatSettingValue(
                                        setting.value,
                                        meta.unit,
                                    )}
                                </span>
                            ) : typeof value === 'boolean' ? (
                                <div className="flex items-center gap-2.5">
                                    <Switch
                                        id={id}
                                        checked={value}
                                        onCheckedChange={(checked) =>
                                            setValues((prev) => ({
                                                ...prev,
                                                [setting.key]: checked,
                                            }))
                                        }
                                    />
                                    <span className="text-sm text-fg-2">
                                        {value ? 'Activado' : 'Desactivado'}
                                    </span>
                                </div>
                            ) : setting.valueType === 'number' ? (
                                <div className="flex items-center gap-2">
                                    <Input
                                        id={id}
                                        type="number"
                                        inputMode="decimal"
                                        min={meta.min ?? 0}
                                        value={value}
                                        aria-invalid={Boolean(
                                            errors[setting.key],
                                        )}
                                        onChange={(e) =>
                                            setValues((prev) => ({
                                                ...prev,
                                                [setting.key]: e.target.value,
                                            }))
                                        }
                                        className="w-28 tabular-nums"
                                    />
                                    <span className="text-sm text-fg-3">
                                        {unitLabel(meta.unit, Number(value))}
                                    </span>
                                </div>
                            ) : (
                                <Input
                                    id={id}
                                    value={value}
                                    aria-invalid={Boolean(errors[setting.key])}
                                    onChange={(e) =>
                                        setValues((prev) => ({
                                            ...prev,
                                            [setting.key]: e.target.value,
                                        }))
                                    }
                                />
                            )}
                        </Field>
                    );
                })}
                {canManage && settings.some(isEditable) ? (
                    <FormActions
                        hint={
                            changed.length > 0
                                ? `${changed.length} ${changed.length === 1 ? 'cambio' : 'cambios'} sin guardar`
                                : undefined
                        }
                    >
                        <Button
                            size="sm"
                            onClick={() => void save()}
                            disabled={saving || changed.length === 0}
                        >
                            Guardar cambios
                        </Button>
                    </FormActions>
                ) : null}
            </FormCard>
        </SettingsSection>
    );
}

// ---- Historial de cambios ----

function versionOrigin(version: VersionRow): string {
    const label = version.snapshot?.label;

    if (typeof label === 'string' && label.startsWith('sam-default-v')) {
        return 'Configuración recomendada de SAM';
    }

    return version.createdByType === 'user'
        ? 'Cambio hecho por una persona'
        : 'Cambio automático de SAM';
}

function VersionsBlock({ versions }: { versions: VersionRow[] }) {
    const [open, setOpen] = useState<VersionRow | null>(null);

    return (
        <SettingsSection
            title="Historial de cambios"
            description="Cada vez que alguien guarda la configuración queda un registro. Se muestran los últimos 15."
        >
            {versions.length === 0 ? (
                <FormCard>
                    <EmptyState
                        className="py-6"
                        icon={History}
                        title="Sin cambios registrados"
                        description="El primer registro aparecerá cuando guardes cualquier ajuste de esta configuración."
                    />
                </FormCard>
            ) : (
                <FormCard className="gap-0 p-0">
                    <ul className="divide-y divide-border">
                        {versions.map((version) => {
                            const ago =
                                version.createdAt !== null &&
                                !Number.isNaN(Date.parse(version.createdAt))
                                    ? minutesSince(version.createdAt)
                                    : null;

                            return (
                                <li
                                    key={version.id}
                                    className="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3"
                                >
                                    <span className="w-16 shrink-0 text-xs font-medium text-fg-3 tabular-nums">
                                        Cambio {version.version}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm text-fg-1">
                                            {versionOrigin(version)}
                                        </p>
                                        <p className="text-2xs text-fg-3">
                                            {formatDateTime(version.createdAt)}
                                            {ago !== null ? (
                                                <>
                                                    {' · '}
                                                    <RelativeTime
                                                        minutes={ago}
                                                    />
                                                </>
                                            ) : null}
                                        </p>
                                    </div>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => setOpen(version)}
                                    >
                                        Ver detalle
                                    </Button>
                                </li>
                            );
                        })}
                    </ul>
                </FormCard>
            )}

            <Dialog
                open={open !== null}
                onOpenChange={(value) => !value && setOpen(null)}
            >
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Cambio {open?.version}</DialogTitle>
                        <DialogDescription>
                            Copia técnica de toda la configuración en ese
                            momento. Útil si soporte te pide revisarla.
                        </DialogDescription>
                    </DialogHeader>
                    <pre className="max-h-96 overflow-auto rounded-md bg-surface-2 p-3 font-mono text-2xs text-fg-2">
                        {JSON.stringify(open?.snapshot ?? {}, null, 2)}
                    </pre>
                </DialogContent>
            </Dialog>
        </SettingsSection>
    );
}
