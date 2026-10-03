import { BellRing, Plus, Radio, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import { Field, FormCard } from '@/components/sam/field';
import { ChipToggle, StatePill } from '@/components/sam/settings/controls';
import {
    FormActions,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { channelLabel } from '@/lib/labels';
import { postJson, putJson } from '@/lib/sam-fetch';
import { submit } from '@/lib/submit';
import { MIN_SEVERITY_KEY } from './settings-catalog';
import { CONFIG_SUBMIT, useTeamBase } from './shared';
import type {
    ChannelRow,
    NotificationPolicyRow,
    Option,
    SettingRow,
} from './types';

/** Canales de los que depende el aviso de un botón de pánico. */
const PANIC_CRITICAL_CHANNELS = new Set(['sms', 'voice']);

/** Qué significa cada vía para quien decide apagarla. */
const CHANNEL_HINTS: Record<string, string> = {
    email: 'Correo a cada persona del equipo',
    sms: 'Mensaje de texto · lo usan las alertas de pánico',
    voice: 'Llamada automática · la usa la verificación de pánico',
    whatsapp: 'Mensaje de WhatsApp',
    web: 'Aviso dentro de SAM',
    push: 'Notificación en el teléfono',
};

/** Valor centinela del <Select> para "cualquier tipo de aviso". */
const ANY_NOTIFICATION_TYPE = '__any__';

const SEVERITY_OPTIONS = [
    { value: 'low', label: 'Todas, incluso las de gravedad baja' },
    { value: 'medium', label: 'Media o mayor (recomendado)' },
    { value: 'high', label: 'Alta o mayor' },
    { value: 'critical', label: 'Sólo las críticas' },
];

export function AlertsSection({
    settings,
    channels,
    policies,
    channelTypes,
    typeOptions,
    canManage,
    canManageChannels,
}: {
    settings: SettingRow[];
    channels: ChannelRow[];
    policies: NotificationPolicyRow[];
    channelTypes: Option[];
    typeOptions: Option[];
    canManage: boolean;
    canManageChannels: boolean;
}) {
    return (
        <>
            <ChannelsBlock channels={channels} canManage={canManageChannels} />
            <MinSeverityBlock settings={settings} canManage={canManage} />
            <PoliciesBlock
                // Remonta tras guardar para partir de lo que quedó en el
                // servidor (ids nuevos) y no arrastrar un "sin guardar" falso.
                key={JSON.stringify(policies)}
                policies={policies}
                channelTypes={channelTypes}
                typeOptions={typeOptions}
                canManage={canManage}
            />
        </>
    );
}

// ---- Vías de aviso (V2-B1) ----
// La mensajería la opera SAM con credenciales de plataforma: la empresa no
// configura proveedores, sólo apaga o enciende cada vía para su equipo.

function ChannelsBlock({
    channels,
    canManage,
}: {
    channels: ChannelRow[];
    canManage: boolean;
}) {
    const base = useTeamBase();
    // Apagar SMS o voz deja sin aviso al botón de pánico: se confirma antes.
    const [confirming, setConfirming] = useState<ChannelRow | null>(null);
    const [pending, setPending] = useState<number | null>(null);

    const toggle = async (channel: ChannelRow) => {
        if (base === null) {
            return;
        }

        setPending(channel.id);
        await submit(
            postJson(`${base}/channels/${channel.id}/toggle`, {
                enabled: !channel.enabledForTeam,
            }),
            channel.enabledForTeam
                ? `${channelLabel(channel.channelType)} apagado para tu equipo.`
                : `${channelLabel(channel.channelType)} encendido para tu equipo.`,
            CONFIG_SUBMIT,
        );
        setPending(null);
    };

    const request = (channel: ChannelRow) => {
        if (
            channel.enabledForTeam &&
            PANIC_CRITICAL_CHANNELS.has(channel.channelType ?? '')
        ) {
            setConfirming(channel);

            return;
        }

        void toggle(channel);
    };

    return (
        <SettingsSection
            title="Vías de aviso"
            description="SAM envía los correos, SMS, WhatsApp y llamadas por ti: no necesitas contratar ni configurar nada. Apaga una vía si tu equipo no la quiere."
        >
            {channels.length === 0 ? (
                <FormCard>
                    <EmptyState
                        className="py-6"
                        icon={Radio}
                        title="Aún no hay vías de aviso"
                        description="SAM todavía no habilitó la mensajería para tu cuenta. Escribe a soporte para activarla."
                    />
                </FormCard>
            ) : (
                <FormCard className="gap-0 p-0">
                    <ul className="divide-y divide-border">
                        {channels.map((channel) => {
                            const on =
                                channel.isActive && channel.enabledForTeam;
                            const id = `tc-channel-${channel.id}`;

                            return (
                                <li
                                    key={channel.id}
                                    className="flex items-center gap-3 px-5 py-3"
                                >
                                    <div className="min-w-0 flex-1">
                                        <label
                                            htmlFor={id}
                                            className="block text-sm font-medium text-fg-1"
                                        >
                                            {channelLabel(channel.channelType)}
                                        </label>
                                        <span className="block text-2xs text-fg-3">
                                            {!channel.isActive
                                                ? 'No disponible por ahora'
                                                : (CHANNEL_HINTS[
                                                      channel.channelType ?? ''
                                                  ] ?? channel.name)}
                                        </span>
                                    </div>
                                    <StatePill
                                        on={on}
                                        onLabel="Encendido"
                                        offLabel={
                                            channel.isActive
                                                ? 'Apagado'
                                                : 'No disponible'
                                        }
                                    />
                                    {canManage ? (
                                        <Switch
                                            id={id}
                                            checked={channel.enabledForTeam}
                                            disabled={
                                                !channel.isActive ||
                                                pending === channel.id
                                            }
                                            onCheckedChange={() =>
                                                request(channel)
                                            }
                                        />
                                    ) : null}
                                </li>
                            );
                        })}
                    </ul>
                </FormCard>
            )}

            <ConfirmDialog
                open={confirming !== null}
                title={`¿Apagar ${confirming ? channelLabel(confirming.channelType) : ''} para tu equipo?`}
                description="Las alertas del botón de pánico dependen de esta vía: la llamada de verificación y los avisos urgentes salen por SMS y voz. Si la apagas, un pánico real puede quedar sin aviso fuera de SAM."
                confirmLabel="Apagar de todos modos"
                onConfirm={async () => {
                    if (confirming) {
                        await toggle(confirming);
                    }

                    setConfirming(null);
                }}
                onOpenChange={(open) => !open && setConfirming(null)}
            />
        </SettingsSection>
    );
}

// ---- Gravedad mínima para avisar fuera de SAM ----

function MinSeverityBlock({
    settings,
    canManage,
}: {
    settings: SettingRow[];
    canManage: boolean;
}) {
    const base = useTeamBase();
    const initial = String(
        settings.find((s) => s.key === MIN_SEVERITY_KEY)?.value ?? 'medium',
    );
    const [minSeverity, setMinSeverity] = useState(initial);
    const [saving, setSaving] = useState(false);

    const save = async () => {
        if (base === null || saving) {
            return;
        }

        setSaving(true);
        await submit(
            putJson(`${base}/settings`, {
                settings: [
                    {
                        setting_key: MIN_SEVERITY_KEY,
                        setting_group: 'notification',
                        value_type: 'string',
                        value: minSeverity,
                    },
                ],
            }),
            'Nivel de aviso guardado.',
            CONFIG_SUBMIT,
        );
        setSaving(false);
    };

    return (
        <SettingsSection
            title="Cuándo avisar fuera de SAM"
            description="Evita que los incidentes menores lleguen al teléfono de tu equipo."
        >
            <FormCard>
                <Field
                    label="Avisar por correo, SMS o llamada a partir de"
                    help="Los incidentes por debajo de este nivel sólo aparecen dentro de SAM."
                    htmlFor="tc-min-severity"
                >
                    <Select
                        value={minSeverity}
                        disabled={!canManage}
                        onValueChange={setMinSeverity}
                    >
                        <SelectTrigger
                            id="tc-min-severity"
                            className="h-9 w-full sm:w-80"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {SEVERITY_OPTIONS.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                {canManage ? (
                    <FormActions>
                        <Button
                            size="sm"
                            onClick={() => void save()}
                            disabled={saving || minSeverity === initial}
                        >
                            Guardar cambios
                        </Button>
                    </FormActions>
                ) : null}
            </FormCard>
        </SettingsSection>
    );
}

// ---- Reglas por tipo de aviso ----

/** Código interno único para una regla nueva, derivado del tipo elegido. */
function policyCodeFor(type: string | null, taken: Set<string>): string {
    const stem = (type ?? 'todos')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
    let code = `aviso_${stem}`;
    let n = 2;

    while (taken.has(code)) {
        code = `aviso_${stem}_${n}`;
        n++;
    }

    return code;
}

function PoliciesBlock({
    policies,
    channelTypes,
    typeOptions,
    canManage,
}: {
    policies: NotificationPolicyRow[];
    channelTypes: Option[];
    typeOptions: Option[];
    canManage: boolean;
}) {
    const base = useTeamBase();
    const [drafts, setDrafts] = useState<NotificationPolicyRow[]>(policies);
    const [saving, setSaving] = useState(false);

    const typeLabel = (type: string | null) =>
        type === null
            ? 'Cualquier aviso'
            : (typeOptions.find((option) => option.value === type)?.label ??
              type);

    const patch = (index: number, change: Partial<NotificationPolicyRow>) =>
        setDrafts((prev) =>
            prev.map((policy, i) =>
                i === index ? { ...policy, ...change } : policy,
            ),
        );

    const changeType = (index: number, type: string | null) => {
        const policy = drafts[index];

        patch(index, {
            notificationType: type,
            // Una regla nueva toma su código del tipo; las guardadas lo
            // conservan (es su identidad en el backend).
            ...(policy.id === 0
                ? {
                      policyCode: policyCodeFor(
                          type,
                          new Set(
                              drafts
                                  .filter((_, i) => i !== index)
                                  .map((p) => p.policyCode),
                          ),
                      ),
                  }
                : {}),
        });
    };

    const toggleChannel = (index: number, channel: string) => {
        const policy = drafts[index];
        const has = policy.allowedChannels.includes(channel);

        patch(index, {
            allowedChannels: has
                ? policy.allowedChannels.filter((c) => c !== channel)
                : [...policy.allowedChannels, channel],
        });
    };

    const save = async () => {
        if (base === null) {
            return;
        }

        const invalid = drafts.find((d) => d.allowedChannels.length === 0);

        if (invalid) {
            toast.error(
                `La regla "${typeLabel(invalid.notificationType)}" necesita al menos una vía.`,
            );

            return;
        }

        setSaving(true);
        await submit(
            putJson(`${base}/notifications`, {
                policies: drafts.map((d) => ({
                    policy_code: d.policyCode,
                    notification_type: d.notificationType,
                    priority: d.priority,
                    allowed_channels: d.allowedChannels,
                    fallback_channels: d.fallbackChannels,
                    is_active: d.isActive,
                })),
            }),
            'Reglas de aviso guardadas.',
            CONFIG_SUBMIT,
        );
        setSaving(false);
    };

    const addPolicy = () => {
        setDrafts((prev) => [
            ...prev,
            {
                id: 0,
                policyCode: policyCodeFor(
                    null,
                    new Set(prev.map((p) => p.policyCode)),
                ),
                notificationType: null,
                priority: null,
                allowedChannels: channelTypes.some((c) => c.value === 'email')
                    ? ['email']
                    : channelTypes.slice(0, 1).map((c) => c.value),
                fallbackChannels: [],
                isActive: true,
            },
        ]);
    };

    // D-12: quitar una regla aún no guardada sin recargar la página.
    const removeDraft = (index: number) => {
        setDrafts((prev) => prev.filter((_, i) => i !== index));
    };

    const dirty = JSON.stringify(drafts) !== JSON.stringify(policies);

    return (
        <SettingsSection
            title="Vías por tipo de aviso"
            description="Elige por dónde llega cada tipo de aviso. Cada persona puede afinarlo después en «Mis avisos»."
            actions={
                canManage && drafts.length > 0 ? (
                    <Button size="sm" variant="outline" onClick={addPolicy}>
                        <Plus className="size-3.5" /> Añadir regla
                    </Button>
                ) : null
            }
        >
            {drafts.length === 0 ? (
                <FormCard>
                    <EmptyState
                        className="py-6"
                        icon={BellRing}
                        title="Usando las vías recomendadas"
                        description="Sin reglas propias, SAM avisa por correo y dentro de la app, y añade SMS en los incidentes críticos."
                        action={
                            canManage ? (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={addPolicy}
                                >
                                    <Plus className="size-3.5" /> Añadir una
                                    regla
                                </Button>
                            ) : null
                        }
                    />
                </FormCard>
            ) : (
                <FormCard className="gap-0 p-0">
                    <ul className="divide-y divide-border">
                        {drafts.map((policy, index) => (
                            <li
                                key={`${policy.policyCode}-${index}`}
                                className="flex flex-col gap-3 px-5 py-4"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <Select
                                        value={
                                            policy.notificationType ??
                                            ANY_NOTIFICATION_TYPE
                                        }
                                        disabled={!canManage}
                                        onValueChange={(value) =>
                                            changeType(
                                                index,
                                                value === ANY_NOTIFICATION_TYPE
                                                    ? null
                                                    : value,
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            aria-label="Tipo de aviso"
                                            className="h-9 w-full font-medium sm:w-80"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                value={ANY_NOTIFICATION_TYPE}
                                            >
                                                Cualquier aviso
                                            </SelectItem>
                                            {typeOptions.map((option) => (
                                                <SelectItem
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <div className="ml-auto flex items-center gap-2">
                                        {policy.id === 0 ? (
                                            <span className="text-2xs text-fg-3">
                                                Sin guardar
                                            </span>
                                        ) : null}
                                        <Switch
                                            checked={policy.isActive}
                                            disabled={!canManage}
                                            aria-label={`Regla activa: ${typeLabel(policy.notificationType)}`}
                                            onCheckedChange={(checked) =>
                                                patch(index, {
                                                    isActive: checked,
                                                })
                                            }
                                        />
                                        <span className="w-14 text-xs text-fg-2">
                                            {policy.isActive
                                                ? 'Activa'
                                                : 'Pausada'}
                                        </span>
                                        {canManage && policy.id === 0 ? (
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                className="size-8 text-fg-3 hover:text-severity-critical"
                                                aria-label="Quitar regla sin guardar"
                                                onClick={() =>
                                                    removeDraft(index)
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        ) : null}
                                    </div>
                                </div>
                                <div className="flex flex-wrap items-center gap-1.5">
                                    <span className="mr-1 text-xs text-fg-3">
                                        Enviar por
                                    </span>
                                    {channelTypes.map((channel) => (
                                        <ChipToggle
                                            key={channel.value}
                                            active={policy.allowedChannels.includes(
                                                channel.value,
                                            )}
                                            disabled={!canManage}
                                            onToggle={() =>
                                                toggleChannel(
                                                    index,
                                                    channel.value,
                                                )
                                            }
                                        >
                                            {channel.label}
                                        </ChipToggle>
                                    ))}
                                </div>
                                {policy.allowedChannels.length === 0 ? (
                                    <p className="text-2xs text-severity-high">
                                        Elige al menos una vía.
                                    </p>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                    {canManage ? (
                        <div className="flex flex-wrap items-center justify-between gap-2 rounded-b-lg border-t border-border bg-surface-2 px-5 py-3">
                            <span className="text-2xs text-fg-3">
                                {dirty
                                    ? 'Tienes cambios sin guardar.'
                                    : 'Sin cambios pendientes.'}
                            </span>
                            <Button
                                size="sm"
                                onClick={() => void save()}
                                disabled={saving || !dirty}
                            >
                                Guardar reglas
                            </Button>
                        </div>
                    ) : null}
                </FormCard>
            )}
        </SettingsSection>
    );
}
