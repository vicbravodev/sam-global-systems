import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ConditionBuilder } from '@/components/sam/condition-builder';
import type { ConditionFieldDef } from '@/components/sam/condition-builder';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    deleteJson,
    postJson,
    putJson,
    readErrorMessage,
} from '@/lib/sam-fetch';

// ---- Types ----

interface SettingRow {
    id: number;
    key: string;
    group: string | null;
    valueType: string | null;
    value: unknown;
    isActive: boolean;
    version: number;
}

interface AiProfile {
    profileCode: string | null;
    name: string | null;
    description: string | null;
    riskTolerance: string | null;
    falsePositiveTolerance: string | null;
    automationLevel: string | null;
    mediaStrategy: string | null;
}

interface NotificationPolicyRow {
    id: number;
    policyCode: string;
    notificationType: string | null;
    priority: string | null;
    allowedChannels: string[];
    fallbackChannels: string[];
    isActive: boolean;
}

interface EscalationConfigRow {
    id: number;
    escalationType: string;
    triggerConditions: Record<string, unknown>;
    steps: unknown[];
    timeConstraints: Record<string, unknown> | null;
    isActive: boolean;
}

interface ScheduleProfileRow {
    id: number;
    profileCode: string;
    timezone: string;
    operatingHours: Record<string, unknown>;
    shiftRules: unknown[] | null;
    afterHoursBehavior: Record<string, unknown> | null;
    isActive: boolean;
}

interface BrandingProp {
    displayName: string | null;
    primaryColor: string | null;
    secondaryColor: string | null;
    emailSignature: string | null;
    logoUrl: string | null;
}

interface ChannelRow {
    id: number;
    code: string;
    name: string;
    provider: string | null;
    channelType: string | null;
    isActive: boolean;
    isGlobal: boolean;
    configSummary: Record<string, string>;
}

interface VersionRow {
    id: number;
    version: number;
    createdByType: string | null;
    createdAt: string | null;
    snapshot: Record<string, unknown> | null;
}

interface TenantConfigProps {
    settings: SettingRow[];
    aiProfile: AiProfile;
    aiProfileOptions: {
        riskTolerances: string[];
        falsePositiveTolerances: string[];
        automationLevels: string[];
        mediaStrategies: string[];
    };
    notificationPolicies: NotificationPolicyRow[];
    escalationConfigs: EscalationConfigRow[];
    escalationConditionFields: ConditionFieldDef[];
    scheduleProfiles: ScheduleProfileRow[];
    versions: VersionRow[];
    channels: ChannelRow[];
    branding: BrandingProp;
    channelTypes: string[];
    canManageChannels: boolean;
    canManage: boolean;
}

const TABS = [
    { key: 'general', label: 'General' },
    { key: 'ai', label: 'Perfil IA' },
    { key: 'notifications', label: 'Notificaciones' },
    { key: 'escalation', label: 'Escalación' },
    { key: 'schedule', label: 'Horario on-call' },
    { key: 'channels', label: 'Canales' },
    { key: 'branding', label: 'Marca' },
    { key: 'versions', label: 'Versiones' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

const CHANNEL_OPTIONS = [
    'email',
    'web',
    'sms',
    'whatsapp',
    'push',
    'slack',
    'webhook',
];

const MEDIA_AUTO_REQUEST_KEY = 'media.auto_request_on_critical';
const PANIC_AUTO_CLOSE_KEY = 'panic.auto_close_on_external_resolution';
const LIVE_LOCATION_KEY = 'context.live_location_staleness_seconds';

function useTeamBase(): string | null {
    const page = usePage();
    const slug =
        (
            page.props as unknown as {
                currentTeam?: { slug?: string | null } | null;
            }
        ).currentTeam?.slug ?? null;

    return slug ? `/${slug}/settings/tenant-config` : null;
}

async function submit(
    promise: Promise<Response>,
    successMessage: string,
): Promise<boolean> {
    try {
        const response = await promise;

        if (response.ok || response.status === 201) {
            toast.success(successMessage);
            router.reload();

            return true;
        }

        if (response.status === 403) {
            toast.error('No tienes permisos para editar la configuración.');
        } else {
            toast.error(
                (await readErrorMessage(response)) ??
                    'No se pudo guardar la configuración.',
            );
        }
    } catch {
        toast.error('Error de red. Vuelve a intentarlo.');
    }

    return false;
}

// ---- General settings tab ----

function GeneralTab({
    settings,
    canManage,
}: {
    settings: SettingRow[];
    canManage: boolean;
}) {
    const base = useTeamBase();
    const byKey = (key: string) => settings.find((s) => s.key === key);

    const [autoRequest, setAutoRequest] = useState(
        Boolean(byKey(MEDIA_AUTO_REQUEST_KEY)?.value ?? false),
    );
    const [panicMode, setPanicMode] = useState(
        String(byKey(PANIC_AUTO_CLOSE_KEY)?.value ?? 'annotate'),
    );
    const [staleness, setStaleness] = useState(
        String(byKey(LIVE_LOCATION_KEY)?.value ?? '120'),
    );
    const [saving, setSaving] = useState(false);

    const save = async () => {
        if (base === null) {
            return;
        }

        setSaving(true);
        await submit(
            putJson(`${base}/settings`, {
                settings: [
                    {
                        setting_key: MEDIA_AUTO_REQUEST_KEY,
                        setting_group: 'operational',
                        value_type: 'boolean',
                        value: autoRequest,
                    },
                    {
                        setting_key: PANIC_AUTO_CLOSE_KEY,
                        setting_group: 'operational',
                        value_type: 'string',
                        value: panicMode,
                    },
                    {
                        setting_key: LIVE_LOCATION_KEY,
                        setting_group: 'operational',
                        value_type: 'number',
                        value: Number(staleness) || 120,
                    },
                ],
            }),
            'Configuración guardada.',
        );
        setSaving(false);
    };

    const otherSettings = settings.filter(
        (s) =>
            ![
                MEDIA_AUTO_REQUEST_KEY,
                PANIC_AUTO_CLOSE_KEY,
                LIVE_LOCATION_KEY,
            ].includes(s.key),
    );

    return (
        <div className="flex flex-col gap-4">
            <Card>
                <CardHeader>
                    <CardTitle className="text-[13px] uppercase">
                        Pipeline de emergencias
                    </CardTitle>
                </CardHeader>
                <CardContent className="flex flex-col gap-4 text-[13px]">
                    <label className="flex items-start gap-2">
                        <input
                            type="checkbox"
                            checked={autoRequest}
                            disabled={!canManage}
                            onChange={(e) => setAutoRequest(e.target.checked)}
                            className="mt-0.5"
                        />
                        <span>
                            <span className="font-medium text-fg-1">
                                Solicitar footage automáticamente en eventos
                                críticos
                            </span>
                            <span className="block text-[12px] text-fg-3">
                                {MEDIA_AUTO_REQUEST_KEY} — consume cuota de
                                retrievals del proveedor (default: apagado).
                            </span>
                        </span>
                    </label>

                    <div className="flex flex-col gap-1">
                        <Label className="text-[12px]">
                            Resolución externa de pánico ({PANIC_AUTO_CLOSE_KEY}
                            )
                        </Label>
                        <select
                            value={panicMode}
                            disabled={!canManage}
                            onChange={(e) => setPanicMode(e.target.value)}
                            className="w-64 rounded-md border border-border bg-surface-1 px-2 py-1.5 text-[13px]"
                        >
                            <option value="annotate">
                                Solo anotar (annotate)
                            </option>
                            <option value="close">
                                Cerrar incidente (close)
                            </option>
                        </select>
                    </div>

                    <div className="flex flex-col gap-1">
                        <Label className="text-[12px]">
                            GPS fresco: umbral de obsolescencia en segundos (
                            {LIVE_LOCATION_KEY})
                        </Label>
                        <Input
                            type="number"
                            value={staleness}
                            disabled={!canManage}
                            onChange={(e) => setStaleness(e.target.value)}
                            className="w-40"
                        />
                    </div>

                    {canManage && (
                        <div>
                            <Button size="sm" onClick={save} disabled={saving}>
                                Guardar
                            </Button>
                        </div>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="text-[13px] uppercase">
                        Otros settings ({otherSettings.length})
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {otherSettings.length === 0 ? (
                        <p className="text-[12px] text-fg-3">
                            Sin settings adicionales.
                        </p>
                    ) : (
                        <table className="w-full text-left text-[12px]">
                            <thead className="text-[11px] text-fg-3 uppercase">
                                <tr>
                                    <th className="py-1">Key</th>
                                    <th className="py-1">Grupo</th>
                                    <th className="py-1">Valor</th>
                                    <th className="py-1">v</th>
                                </tr>
                            </thead>
                            <tbody>
                                {otherSettings.map((s) => (
                                    <tr
                                        key={s.id}
                                        className="border-t border-border/50 text-fg-2"
                                    >
                                        <td className="py-1.5 font-mono text-[11px]">
                                            {s.key}
                                        </td>
                                        <td className="py-1.5">{s.group}</td>
                                        <td className="py-1.5 font-mono text-[11px]">
                                            {JSON.stringify(s.value)}
                                        </td>
                                        <td className="py-1.5">{s.version}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

// ---- AI profile tab ----

function AiTab({
    profile,
    options,
    canManage,
}: {
    profile: AiProfile;
    options: TenantConfigProps['aiProfileOptions'];
    canManage: boolean;
}) {
    const base = useTeamBase();
    const [form, setForm] = useState({
        profile_code: profile.profileCode ?? 'custom',
        name: profile.name ?? 'Perfil del tenant',
        description: profile.description ?? '',
        risk_tolerance: profile.riskTolerance ?? 'medium',
        false_positive_tolerance: profile.falsePositiveTolerance ?? 'medium',
        automation_level: profile.automationLevel ?? 'assisted',
        media_strategy: profile.mediaStrategy ?? 'optional',
    });
    const [saving, setSaving] = useState(false);

    const set = (key: string, value: string) =>
        setForm((prev) => ({ ...prev, [key]: value }));

    const selects: {
        key: keyof typeof form;
        label: string;
        options: string[];
    }[] = [
        {
            key: 'risk_tolerance',
            label: 'Tolerancia al riesgo',
            options: options.riskTolerances,
        },
        {
            key: 'false_positive_tolerance',
            label: 'Tolerancia a falsos positivos',
            options: options.falsePositiveTolerances,
        },
        {
            key: 'automation_level',
            label: 'Nivel de automatización',
            options: options.automationLevels,
        },
        {
            key: 'media_strategy',
            label: 'Estrategia de media',
            options: options.mediaStrategies,
        },
    ];

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
            'Perfil IA guardado.',
        );
        setSaving(false);
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-[13px] uppercase">
                    Perfil de evaluación IA
                </CardTitle>
            </CardHeader>
            <CardContent className="flex max-w-xl flex-col gap-3 text-[13px]">
                <div className="flex flex-col gap-1">
                    <Label className="text-[12px]">Nombre</Label>
                    <Input
                        value={form.name}
                        disabled={!canManage}
                        onChange={(e) => set('name', e.target.value)}
                    />
                </div>
                <div className="flex flex-col gap-1">
                    <Label className="text-[12px]">Descripción</Label>
                    <Input
                        value={form.description}
                        disabled={!canManage}
                        onChange={(e) => set('description', e.target.value)}
                    />
                </div>
                {selects.map((select) => (
                    <div key={select.key} className="flex flex-col gap-1">
                        <Label className="text-[12px]">{select.label}</Label>
                        <select
                            value={form[select.key]}
                            disabled={!canManage}
                            onChange={(e) => set(select.key, e.target.value)}
                            className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-[13px]"
                        >
                            {select.options.map((option) => (
                                <option key={option} value={option}>
                                    {option}
                                </option>
                            ))}
                        </select>
                    </div>
                ))}
                {canManage && (
                    <div>
                        <Button size="sm" onClick={save} disabled={saving}>
                            Guardar perfil
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

// ---- Notification policies tab ----

function NotificationsTab({
    policies,
    canManage,
}: {
    policies: NotificationPolicyRow[];
    canManage: boolean;
}) {
    const base = useTeamBase();
    const [drafts, setDrafts] = useState<NotificationPolicyRow[]>(policies);
    const [saving, setSaving] = useState(false);

    const toggleChannel = (index: number, channel: string) => {
        setDrafts((prev) =>
            prev.map((policy, i) => {
                if (i !== index) {
                    return policy;
                }

                const has = policy.allowedChannels.includes(channel);

                return {
                    ...policy,
                    allowedChannels: has
                        ? policy.allowedChannels.filter((c) => c !== channel)
                        : [...policy.allowedChannels, channel],
                };
            }),
        );
    };

    const save = async () => {
        if (base === null) {
            return;
        }

        const invalid = drafts.find((d) => d.allowedChannels.length === 0);

        if (invalid) {
            toast.error(
                `La política ${invalid.policyCode} necesita al menos un canal.`,
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
            'Políticas de notificación guardadas.',
        );
        setSaving(false);
    };

    const addPolicy = () => {
        setDrafts((prev) => [
            ...prev,
            {
                id: 0,
                policyCode: `policy_${prev.length + 1}`,
                notificationType: null,
                priority: null,
                allowedChannels: ['email'],
                fallbackChannels: [],
                isActive: true,
            },
        ]);
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-[13px] uppercase">
                    Políticas de notificación
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {drafts.length === 0 && (
                    <p className="text-[12px] text-fg-3">
                        Sin políticas — aplican los defaults del sistema (email
                        + web; críticos añaden sms/push).
                    </p>
                )}
                {drafts.map((policy, index) => (
                    <div
                        key={`${policy.policyCode}-${index}`}
                        className="rounded-[6px] border border-border p-3"
                    >
                        <div className="mb-2 flex flex-wrap items-center gap-2">
                            <Input
                                value={policy.policyCode}
                                disabled={!canManage || policy.id !== 0}
                                onChange={(e) =>
                                    setDrafts((prev) =>
                                        prev.map((p, i) =>
                                            i === index
                                                ? {
                                                      ...p,
                                                      policyCode:
                                                          e.target.value,
                                                  }
                                                : p,
                                        ),
                                    )
                                }
                                className="w-52 font-mono text-[12px]"
                            />
                            <Input
                                value={policy.notificationType ?? ''}
                                placeholder="notification_type (opcional)"
                                disabled={!canManage}
                                onChange={(e) =>
                                    setDrafts((prev) =>
                                        prev.map((p, i) =>
                                            i === index
                                                ? {
                                                      ...p,
                                                      notificationType:
                                                          e.target.value === ''
                                                              ? null
                                                              : e.target.value,
                                                  }
                                                : p,
                                        ),
                                    )
                                }
                                className="w-56 text-[12px]"
                            />
                            <label className="flex items-center gap-1 text-[12px] text-fg-2">
                                <input
                                    type="checkbox"
                                    checked={policy.isActive}
                                    disabled={!canManage}
                                    onChange={(e) =>
                                        setDrafts((prev) =>
                                            prev.map((p, i) =>
                                                i === index
                                                    ? {
                                                          ...p,
                                                          isActive:
                                                              e.target.checked,
                                                      }
                                                    : p,
                                            ),
                                        )
                                    }
                                />
                                activa
                            </label>
                        </div>
                        <div className="flex flex-wrap gap-3">
                            {CHANNEL_OPTIONS.map((channel) => (
                                <label
                                    key={channel}
                                    className="flex items-center gap-1 text-[12px] text-fg-2"
                                >
                                    <input
                                        type="checkbox"
                                        checked={policy.allowedChannels.includes(
                                            channel,
                                        )}
                                        disabled={!canManage}
                                        onChange={() =>
                                            toggleChannel(index, channel)
                                        }
                                    />
                                    {channel}
                                </label>
                            ))}
                        </div>
                    </div>
                ))}
                {canManage && (
                    <div className="flex gap-2">
                        <Button size="sm" variant="outline" onClick={addPolicy}>
                            Añadir política
                        </Button>
                        {drafts.length > 0 && (
                            <Button size="sm" onClick={save} disabled={saving}>
                                Guardar políticas
                            </Button>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

// ---- JSON editors (escalation + schedule) ----

function JsonField({
    label,
    value,
    onChange,
    disabled,
    rows = 6,
}: {
    label: string;
    value: string;
    onChange: (next: string) => void;
    disabled: boolean;
    rows?: number;
}) {
    return (
        <div className="flex flex-col gap-1">
            <Label className="text-[12px]">{label}</Label>
            <textarea
                value={value}
                disabled={disabled}
                onChange={(e) => onChange(e.target.value)}
                rows={rows}
                spellCheck={false}
                className="rounded-md border border-border bg-surface-2 p-2 font-mono text-[11px] leading-relaxed text-fg-2"
            />
        </div>
    );
}

function parseJson(
    raw: string,
    label: string,
): Record<string, unknown> | unknown[] | null {
    try {
        return JSON.parse(raw) as Record<string, unknown> | unknown[];
    } catch {
        toast.error(`JSON inválido en ${label}.`);

        return null;
    }
}

function EscalationTab({
    configs,
    conditionFields,
    canManage,
}: {
    configs: EscalationConfigRow[];
    conditionFields: ConditionFieldDef[];
    canManage: boolean;
}) {
    const base = useTeamBase();
    const [saving, setSaving] = useState(false);

    const saveExisting = async (
        config: EscalationConfigRow,
        raw: string,
        conditions: Record<string, unknown>,
    ) => {
        if (base === null) {
            return;
        }

        const steps = parseJson(raw, `steps de ${config.escalationType}`);

        if (steps === null) {
            return;
        }

        setSaving(true);
        await submit(
            putJson(`${base}/escalation/${config.id}`, {
                steps,
                trigger_conditions: conditions,
            }),
            'Escalación guardada.',
        );
        setSaving(false);
    };

    const createDefault = async () => {
        if (base === null) {
            return;
        }

        setSaving(true);
        await submit(
            postJson(`${base}/escalation`, {
                escalation_type: 'incident_critical',
                trigger_conditions: { priority: 'critical' },
                steps: [
                    {
                        delay_minutes: 5,
                        channels: ['sms', 'push'],
                        recipient: 'team_lead',
                    },
                    {
                        delay_minutes: 15,
                        channels: ['sms', 'email'],
                        recipient: 'tenant_admin',
                    },
                ],
                is_active: true,
            }),
            'Escalación creada.',
        );
        setSaving(false);
    };

    return (
        <div className="flex flex-col gap-4">
            {configs.length === 0 && (
                <Card>
                    <CardContent className="flex items-center justify-between py-4 text-[12px] text-fg-3">
                        Sin configuración de escalación — el SLA (P6) no escala
                        por niveles hasta definir los steps.
                        {canManage && (
                            <Button
                                size="sm"
                                onClick={createDefault}
                                disabled={saving}
                            >
                                Crear escalación de críticos
                            </Button>
                        )}
                    </CardContent>
                </Card>
            )}
            {configs.map((config) => (
                <EscalationCard
                    key={config.id}
                    config={config}
                    conditionFields={conditionFields}
                    canManage={canManage}
                    saving={saving}
                    onSave={saveExisting}
                />
            ))}
        </div>
    );
}

function EscalationCard({
    config,
    conditionFields,
    canManage,
    saving,
    onSave,
}: {
    config: EscalationConfigRow;
    conditionFields: ConditionFieldDef[];
    canManage: boolean;
    saving: boolean;
    onSave: (
        config: EscalationConfigRow,
        raw: string,
        conditions: Record<string, unknown>,
    ) => Promise<void>;
}) {
    const [raw, setRaw] = useState(JSON.stringify(config.steps, null, 2));
    const [conditions, setConditions] = useState<Record<string, unknown>>(
        config.triggerConditions ?? {},
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-[13px] uppercase">
                    {config.escalationType}
                    <Badge
                        variant="outline"
                        className={
                            config.isActive ? 'text-severity-low' : 'text-fg-3'
                        }
                    >
                        {config.isActive ? 'activa' : 'inactiva'}
                    </Badge>
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                <div className="flex flex-col gap-1">
                    <Label className="text-[12px]">
                        Condiciones del disparador
                    </Label>
                    <ConditionBuilder
                        variant="flat-equality"
                        fields={conditionFields}
                        allowUnknownFields
                        value={conditions}
                        onChange={setConditions}
                        disabled={!canManage}
                    />
                </div>
                <JsonField
                    label="Steps (delay_minutes / channels / recipient)"
                    value={raw}
                    onChange={setRaw}
                    disabled={!canManage}
                />
                {canManage && (
                    <div>
                        <Button
                            size="sm"
                            onClick={() => onSave(config, raw, conditions)}
                            disabled={saving}
                        >
                            Guardar escalación
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function ScheduleTab({
    profiles,
    canManage,
}: {
    profiles: ScheduleProfileRow[];
    canManage: boolean;
}) {
    const base = useTeamBase();
    const [saving, setSaving] = useState(false);

    if (profiles.length === 0) {
        return (
            <Card>
                <CardContent className="py-4 text-[12px] text-fg-3">
                    Sin perfiles de horario — la asignación on-call (P5) usa el
                    fallback (primer admin del equipo). Los perfiles se crean al
                    configurar la integración o por API.
                </CardContent>
            </Card>
        );
    }

    const save = async (
        profile: ScheduleProfileRow,
        rawShifts: string,
        timezone: string,
    ) => {
        if (base === null) {
            return;
        }

        const shiftRules = parseJson(
            rawShifts,
            `shift_rules de ${profile.profileCode}`,
        );

        if (shiftRules === null) {
            return;
        }

        setSaving(true);
        await submit(
            putJson(`${base}/schedule/${profile.id}`, {
                timezone,
                shift_rules: shiftRules,
            }),
            'Horario guardado.',
        );
        setSaving(false);
    };

    return (
        <div className="flex flex-col gap-4">
            {profiles.map((profile) => (
                <ScheduleCard
                    key={profile.id}
                    profile={profile}
                    canManage={canManage}
                    saving={saving}
                    onSave={save}
                />
            ))}
        </div>
    );
}

function ScheduleCard({
    profile,
    canManage,
    saving,
    onSave,
}: {
    profile: ScheduleProfileRow;
    canManage: boolean;
    saving: boolean;
    onSave: (
        profile: ScheduleProfileRow,
        rawShifts: string,
        timezone: string,
    ) => Promise<void>;
}) {
    const [timezone, setTimezone] = useState(profile.timezone);
    const [rawShifts, setRawShifts] = useState(
        JSON.stringify(profile.shiftRules ?? [], null, 2),
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-[13px] uppercase">
                    {profile.profileCode}
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                <div className="flex flex-col gap-1">
                    <Label className="text-[12px]">Zona horaria</Label>
                    <Input
                        value={timezone}
                        disabled={!canManage}
                        onChange={(e) => setTimezone(e.target.value)}
                        className="w-72"
                    />
                </div>
                <JsonField
                    label="Shift rules (turnos on-call que usa P5)"
                    value={rawShifts}
                    onChange={setRawShifts}
                    disabled={!canManage}
                />
                {canManage && (
                    <div>
                        <Button
                            size="sm"
                            onClick={() => onSave(profile, rawShifts, timezone)}
                            disabled={saving}
                        >
                            Guardar horario
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

// ---- Channels tab (F5c) ----

const CHANNEL_CONFIG_FIELDS: Record<string, { key: string; label: string }[]> =
    {
        slack: [{ key: 'slack_webhook_url', label: 'Webhook URL de Slack' }],
        sms: [
            { key: 'twilio_account_sid', label: 'Twilio Account SID' },
            { key: 'twilio_auth_token', label: 'Twilio Auth Token' },
            { key: 'from', label: 'Número emisor (E.164 o MG…)' },
        ],
        whatsapp: [
            { key: 'twilio_account_sid', label: 'Twilio Account SID' },
            { key: 'twilio_auth_token', label: 'Twilio Auth Token' },
            { key: 'from', label: 'Emisor (whatsapp:+…)' },
        ],
        push: [
            {
                key: 'firebase_credentials',
                label: 'Credenciales Firebase (JSON)',
            },
        ],
        webhook: [
            { key: 'url', label: 'URL destino' },
            { key: 'secret', label: 'Secreto HMAC' },
        ],
        email: [],
        web: [],
    };

function ChannelsTab({
    channels,
    channelTypes,
    canManage,
}: {
    channels: ChannelRow[];
    channelTypes: string[];
    canManage: boolean;
}) {
    const base = useTeamBase();
    const [creating, setCreating] = useState(false);
    const [form, setForm] = useState({
        code: '',
        name: '',
        channelType: 'slack',
        config: {} as Record<string, string>,
    });
    const [testAddress, setTestAddress] = useState('');
    const [testingId, setTestingId] = useState<number | null>(null);

    const providerFor = (type: string): string =>
        type === 'sms' || type === 'whatsapp'
            ? 'twilio'
            : type === 'push'
              ? 'firebase'
              : type === 'slack'
                ? 'slack'
                : type === 'webhook'
                  ? 'webhook'
                  : 'mail';

    const create = async () => {
        if (base === null) {
            return;
        }

        if (form.code === '' || form.name === '') {
            toast.error('Código y nombre son obligatorios.');

            return;
        }

        const ok = await submit(
            postJson(`${base}/channels`, {
                code: form.code,
                name: form.name,
                provider: providerFor(form.channelType),
                channel_type: form.channelType,
                config_json: form.config,
                is_active: true,
            }),
            'Canal creado.',
        );

        if (ok) {
            setCreating(false);
        }
    };

    const toggleActive = (channel: ChannelRow) => {
        if (base === null) {
            return;
        }

        void submit(
            putJson(`${base}/channels/${channel.id}`, {
                is_active: !channel.isActive,
            }),
            channel.isActive ? 'Canal desactivado.' : 'Canal activado.',
        );
    };

    const remove = (channel: ChannelRow) => {
        if (base === null) {
            return;
        }

        void submit(
            deleteJson(`${base}/channels/${channel.id}`),
            'Canal eliminado.',
        );
    };

    const testChannel = async (channel: ChannelRow) => {
        if (base === null) {
            return;
        }

        if (testAddress === '') {
            toast.error(
                'Indica el destino de prueba (email, teléfono, user id o URL).',
            );

            return;
        }

        setTestingId(channel.id);

        try {
            const response = await postJson(
                `${base}/channels/${channel.id}/test`,
                { address: testAddress },
            );
            const payload = (await response.json()) as {
                data?: { success?: boolean; error?: string | null };
            };

            if (response.ok && payload.data?.success) {
                toast.success('Mensaje de prueba enviado. Revisa el destino.');
            } else {
                toast.error(
                    payload.data?.error ?? 'La prueba del canal falló.',
                );
            }
        } catch {
            toast.error('Error de red. Vuelve a intentarlo.');
        } finally {
            setTestingId(null);
        }
    };

    const fields = CHANNEL_CONFIG_FIELDS[form.channelType] ?? [];

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center justify-between text-[13px] uppercase">
                    Canales de notificación ({channels.length})
                    {canManage && (
                        <Button
                            size="sm"
                            variant={creating ? 'ghost' : 'outline'}
                            onClick={() => setCreating(!creating)}
                        >
                            {creating ? 'Cancelar' : 'Nuevo canal'}
                        </Button>
                    )}
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {creating && (
                    <div className="flex flex-col gap-2 rounded-[6px] border border-border p-3">
                        <div className="flex flex-wrap gap-2">
                            <Input
                                placeholder="code (ej. twilio_sms)"
                                value={form.code}
                                onChange={(e) =>
                                    setForm({ ...form, code: e.target.value })
                                }
                                className="w-48 font-mono text-[12px]"
                            />
                            <Input
                                placeholder="Nombre"
                                value={form.name}
                                onChange={(e) =>
                                    setForm({ ...form, name: e.target.value })
                                }
                                className="w-56 text-[12px]"
                            />
                            <select
                                value={form.channelType}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        channelType: e.target.value,
                                        config: {},
                                    })
                                }
                                className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-[12px]"
                            >
                                {channelTypes.map((type) => (
                                    <option key={type} value={type}>
                                        {type}
                                    </option>
                                ))}
                            </select>
                        </div>
                        {fields.map((field) => (
                            <div
                                key={field.key}
                                className="flex flex-col gap-1"
                            >
                                <Label className="text-[12px]">
                                    {field.label}
                                </Label>
                                <Input
                                    type="password"
                                    autoComplete="off"
                                    value={form.config[field.key] ?? ''}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            config: {
                                                ...form.config,
                                                [field.key]: e.target.value,
                                            },
                                        })
                                    }
                                    className="max-w-md font-mono text-[12px]"
                                />
                            </div>
                        ))}
                        <div>
                            <Button size="sm" onClick={create}>
                                Crear canal
                            </Button>
                        </div>
                    </div>
                )}

                {canManage && channels.length > 0 && (
                    <div className="flex items-center gap-2">
                        <Label className="text-[12px] whitespace-nowrap">
                            Destino de prueba
                        </Label>
                        <Input
                            placeholder="email, +52…, user id o URL"
                            value={testAddress}
                            onChange={(e) => setTestAddress(e.target.value)}
                            className="max-w-xs text-[12px]"
                        />
                    </div>
                )}

                {channels.length === 0 ? (
                    <p className="text-[12px] text-fg-3">
                        Sin canales — configura Slack, Twilio (SMS/WhatsApp) o
                        FCM para que las notificaciones y B9 operen.
                    </p>
                ) : (
                    <ul className="flex flex-col gap-2">
                        {channels.map((channel) => (
                            <li
                                key={channel.id}
                                className="flex flex-wrap items-center gap-2 rounded-[6px] border border-border p-2.5 text-[12px]"
                            >
                                <Badge
                                    variant="outline"
                                    className="font-mono text-[10px]"
                                >
                                    {channel.channelType}
                                </Badge>
                                <span className="font-medium text-fg-1">
                                    {channel.name}
                                </span>
                                <span className="font-mono text-[11px] text-fg-3">
                                    {Object.entries(channel.configSummary)
                                        .map(
                                            ([key, value]) => `${key}=${value}`,
                                        )
                                        .join(' · ') || 'sin config'}
                                </span>
                                {channel.isGlobal && (
                                    <Badge
                                        variant="outline"
                                        className="text-[10px] text-fg-3"
                                    >
                                        global
                                    </Badge>
                                )}
                                <Badge
                                    variant="outline"
                                    className={
                                        channel.isActive
                                            ? 'text-severity-low'
                                            : 'text-fg-3'
                                    }
                                >
                                    {channel.isActive ? 'activo' : 'inactivo'}
                                </Badge>
                                {canManage && !channel.isGlobal && (
                                    <span className="ml-auto flex gap-1">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={testingId === channel.id}
                                            onClick={() => testChannel(channel)}
                                        >
                                            Probar canal
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                toggleActive(channel)
                                            }
                                        >
                                            {channel.isActive
                                                ? 'Desactivar'
                                                : 'Activar'}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => remove(channel)}
                                        >
                                            Eliminar
                                        </Button>
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

// ---- Branding tab (F7) ----

function BrandingTab({
    branding,
    canManage,
}: {
    branding: BrandingProp;
    canManage: boolean;
}) {
    const base = useTeamBase();
    const [form, setForm] = useState({
        display_name: branding.displayName ?? '',
        primary_color: branding.primaryColor ?? '#2563eb',
        secondary_color: branding.secondaryColor ?? '#0f172a',
        email_signature: branding.emailSignature ?? '',
    });
    const [saving, setSaving] = useState(false);
    const [uploading, setUploading] = useState(false);

    const save = async () => {
        if (base === null) {
            return;
        }

        setSaving(true);
        await submit(
            putJson(`${base}/branding`, {
                display_name:
                    form.display_name === '' ? null : form.display_name,
                primary_color: form.primary_color,
                secondary_color: form.secondary_color,
                email_signature:
                    form.email_signature === '' ? null : form.email_signature,
            }),
            'Marca guardada.',
        );
        setSaving(false);
    };

    const uploadLogo = async (file: File) => {
        if (base === null) {
            return;
        }

        setUploading(true);

        try {
            const body = new FormData();
            body.append('logo', file);

            const token =
                document
                    .querySelector('meta[name=csrf-token]')
                    ?.getAttribute('content') ?? '';

            const response = await fetch(`${base}/branding/logo`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
                body,
            });

            if (response.ok || response.status === 201) {
                toast.success('Logo subido.');
                router.reload();
            } else if (response.status === 403) {
                toast.error('No tienes permisos para cambiar la marca.');
            } else {
                toast.error(
                    (await readErrorMessage(response)) ??
                        'No se pudo subir el logo.',
                );
            }
        } catch {
            toast.error('Error de red. Vuelve a intentarlo.');
        } finally {
            setUploading(false);
        }
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-[13px] uppercase">
                    Marca del tenant
                </CardTitle>
            </CardHeader>
            <CardContent className="flex max-w-xl flex-col gap-3 text-[13px]">
                <div className="flex items-center gap-3">
                    {branding.logoUrl ? (
                        <img
                            src={branding.logoUrl}
                            alt="Logo del tenant"
                            className="h-14 w-14 rounded-[6px] border border-border object-contain"
                        />
                    ) : (
                        <div className="flex h-14 w-14 items-center justify-center rounded-[6px] border border-dashed border-border text-[10px] text-fg-3">
                            sin logo
                        </div>
                    )}
                    {canManage && (
                        <label className="cursor-pointer text-[12px] text-fg-2 underline">
                            {uploading ? 'Subiendo…' : 'Subir logo'}
                            <input
                                type="file"
                                accept="image/*"
                                className="hidden"
                                disabled={uploading}
                                onChange={(e) => {
                                    const file = e.target.files?.[0];

                                    if (file) {
                                        void uploadLogo(file);
                                    }
                                }}
                            />
                        </label>
                    )}
                </div>

                <div className="flex flex-col gap-1">
                    <Label className="text-[12px]">Nombre para mostrar</Label>
                    <Input
                        value={form.display_name}
                        disabled={!canManage}
                        onChange={(e) =>
                            setForm({ ...form, display_name: e.target.value })
                        }
                    />
                </div>
                <div className="flex gap-4">
                    <div className="flex flex-col gap-1">
                        <Label className="text-[12px]">Color primario</Label>
                        <input
                            type="color"
                            value={form.primary_color}
                            disabled={!canManage}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    primary_color: e.target.value,
                                })
                            }
                            className="h-9 w-16 rounded border border-border bg-surface-1"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <Label className="text-[12px]">Color secundario</Label>
                        <input
                            type="color"
                            value={form.secondary_color}
                            disabled={!canManage}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    secondary_color: e.target.value,
                                })
                            }
                            className="h-9 w-16 rounded border border-border bg-surface-1"
                        />
                    </div>
                </div>
                <div className="flex flex-col gap-1">
                    <Label className="text-[12px]">Firma de email</Label>
                    <textarea
                        value={form.email_signature}
                        disabled={!canManage}
                        onChange={(e) =>
                            setForm({
                                ...form,
                                email_signature: e.target.value,
                            })
                        }
                        rows={3}
                        className="rounded-md border border-border bg-surface-2 p-2 text-[12px] text-fg-2"
                    />
                </div>
                {canManage && (
                    <div>
                        <Button size="sm" onClick={save} disabled={saving}>
                            Guardar marca
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

// ---- Versions tab ----

function VersionsTab({ versions }: { versions: VersionRow[] }) {
    const [open, setOpen] = useState<VersionRow | null>(null);

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-[13px] uppercase">
                    Historial de versiones
                </CardTitle>
            </CardHeader>
            <CardContent>
                {versions.length === 0 ? (
                    <p className="text-[12px] text-fg-3">
                        Aún sin versiones — se crean automáticamente al guardar
                        cualquier configuración.
                    </p>
                ) : (
                    <ul className="flex flex-col">
                        {versions.map((version) => (
                            <li
                                key={version.id}
                                className="flex items-center justify-between border-b border-border/50 py-2 text-[12px]"
                            >
                                <span className="font-mono text-fg-1">
                                    v{version.version}
                                </span>
                                <span className="text-fg-3">
                                    {version.createdAt
                                        ? new Date(
                                              version.createdAt,
                                          ).toLocaleString('es')
                                        : '—'}{' '}
                                    · {version.createdByType ?? '—'}
                                </span>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setOpen(version)}
                                >
                                    Ver snapshot
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}

                <Dialog
                    open={open !== null}
                    onOpenChange={(value) => !value && setOpen(null)}
                >
                    <DialogContent className="max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Snapshot v{open?.version}</DialogTitle>
                        </DialogHeader>
                        <pre className="max-h-[60vh] overflow-auto rounded-[6px] bg-surface-2 p-3 font-mono text-[11px] text-fg-2">
                            {JSON.stringify(open?.snapshot ?? {}, null, 2)}
                        </pre>
                    </DialogContent>
                </Dialog>
            </CardContent>
        </Card>
    );
}

// ---- Page ----

export default function TenantConfigPage() {
    const page = usePage();
    const props = page.props as unknown as TenantConfigProps;
    const [tab, setTab] = useState<TabKey>('general');

    return (
        <>
            <Head title="Configuración del tenant" />
            <div className="flex flex-col gap-4 p-5">
                <div>
                    <h1 className="text-[16px] font-semibold text-fg-1">
                        Configuración
                    </h1>
                    <p className="text-[12px] text-fg-3">
                        Ajustes del tenant: pipeline, IA, notificaciones,
                        escalación y horarios on-call.
                    </p>
                </div>

                <div className="flex flex-wrap gap-1 border-b border-border">
                    {TABS.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => setTab(item.key)}
                            className={`px-3 py-2 text-[13px] transition-colors ${
                                tab === item.key
                                    ? 'border-b-2 border-primary font-medium text-fg-1'
                                    : 'text-fg-3 hover:text-fg-1'
                            }`}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>

                {tab === 'general' && (
                    <GeneralTab
                        settings={props.settings}
                        canManage={props.canManage}
                    />
                )}
                {tab === 'ai' && (
                    <AiTab
                        profile={props.aiProfile}
                        options={props.aiProfileOptions}
                        canManage={props.canManage}
                    />
                )}
                {tab === 'notifications' && (
                    <NotificationsTab
                        policies={props.notificationPolicies}
                        canManage={props.canManage}
                    />
                )}
                {tab === 'escalation' && (
                    <EscalationTab
                        configs={props.escalationConfigs}
                        conditionFields={props.escalationConditionFields}
                        canManage={props.canManage}
                    />
                )}
                {tab === 'schedule' && (
                    <ScheduleTab
                        profiles={props.scheduleProfiles}
                        canManage={props.canManage}
                    />
                )}
                {tab === 'channels' && (
                    <ChannelsTab
                        channels={props.channels}
                        channelTypes={props.channelTypes}
                        canManage={props.canManageChannels}
                    />
                )}
                {tab === 'branding' && (
                    <BrandingTab
                        branding={props.branding}
                        canManage={props.canManage}
                    />
                )}
                {tab === 'versions' && (
                    <VersionsTab versions={props.versions} />
                )}
            </div>
        </>
    );
}

TenantConfigPage.layout = (props: {
    currentTeam?: { slug: string } | null;
}) => ({
    breadcrumbs: [
        {
            title: 'Configuración',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/settings/tenant-config`
                : '#',
        },
    ],
});
