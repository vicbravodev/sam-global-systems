import { Head, router } from '@inertiajs/react';
import { Bell, Check } from 'lucide-react';
import { useMemo, useState } from 'react';
import { FormCard } from '@/components/sam/field';
import { ChipToggle } from '@/components/sam/settings/controls';
import {
    SettingsPage,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Switch } from '@/components/ui/switch';
import { update as updatePreferences } from '@/routes/notification-preferences';

interface PreferenceEntry {
    id: number;
    notificationType: string;
    allowedChannels: string[];
    muted: boolean;
}

interface ChannelOption {
    value: string;
    label: string;
}

type Props = {
    preferences?: PreferenceEntry[];
    knownTypes?: string[];
    /** notification_type → etiqueta humana (NotificationTypeLabels). */
    typeLabels?: Record<string, string>;
    channelOptions?: ChannelOption[];
    teamName?: string | null;
};

function sameSet(a: string[], b: string[]): boolean {
    return (
        a.length === b.length &&
        [...a].sort().join(',') === [...b].sort().join(',')
    );
}

function PreferenceRow({
    type,
    label,
    initialChannels,
    initialMuted,
    configured,
    channelOptions,
}: {
    type: string;
    label: string;
    initialChannels: string[];
    initialMuted: boolean;
    configured: boolean;
    channelOptions: ChannelOption[];
}) {
    const [channels, setChannels] = useState<string[]>(initialChannels);
    const [muted, setMuted] = useState(initialMuted);
    const [saving, setSaving] = useState(false);

    const dirty = muted !== initialMuted || !sameSet(channels, initialChannels);

    const toggleChannel = (value: string) => {
        setChannels((current) =>
            current.includes(value)
                ? current.filter((c) => c !== value)
                : [...current, value],
        );
    };

    const save = () => {
        setSaving(true);
        router.put(
            updatePreferences().url,
            {
                notification_type: type,
                allowed_channels: channels,
                muted,
            },
            {
                preserveScroll: true,
                onFinish: () => setSaving(false),
            },
        );
    };

    const mutedId = `pref-${type}-muted`;

    return (
        <li className="flex flex-col gap-3 px-5 py-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0">
                    <p
                        className="truncate text-sm font-medium text-fg-1"
                        title={type}
                    >
                        {label}
                    </p>
                    <p className="text-2xs text-fg-3">
                        {configured
                            ? 'Con tu preferencia'
                            : 'Usa la regla de tu equipo hasta que la cambies'}
                    </p>
                </div>
                <Button
                    size="sm"
                    variant={dirty ? 'default' : 'outline'}
                    onClick={save}
                    disabled={!dirty || saving || channels.length === 0}
                >
                    <Check className="size-3.5" />
                    Guardar
                </Button>
            </div>

            <div className="flex flex-wrap items-center gap-1.5">
                <span className="mr-1 text-xs text-fg-3">Recibir por</span>
                {channelOptions.map((option) => (
                    <ChipToggle
                        key={option.value}
                        active={channels.includes(option.value)}
                        onToggle={() => toggleChannel(option.value)}
                    >
                        {option.label}
                    </ChipToggle>
                ))}
            </div>

            <div className="flex items-center gap-2.5">
                <Switch
                    id={mutedId}
                    checked={muted}
                    onCheckedChange={setMuted}
                />
                <label htmlFor={mutedId} className="text-xs text-fg-2">
                    Silenciar los de prioridad baja y normal
                </label>
            </div>

            {channels.length === 0 ? (
                <p className="text-2xs text-severity-high">
                    Elige al menos una vía para guardar.
                </p>
            ) : null}
        </li>
    );
}

export default function NotificationsSettings({
    preferences = [],
    knownTypes = [],
    typeLabels = {},
    channelOptions = [],
    teamName = null,
}: Props) {
    const byType = useMemo(() => {
        const map = new Map<string, PreferenceEntry>();

        for (const pref of preferences) {
            map.set(pref.notificationType, pref);
        }

        return map;
    }, [preferences]);

    const types = useMemo(() => {
        const all = new Set<string>([
            ...knownTypes,
            ...preferences.map((p) => p.notificationType),
        ]);

        return [...all].sort((a, b) =>
            (typeLabels[a] ?? a).localeCompare(typeLabels[b] ?? b, 'es'),
        );
    }, [knownTypes, preferences, typeLabels]);

    return (
        <>
            <Head title="Mis avisos" />
            <SettingsPage
                title="Mis avisos"
                description={
                    teamName
                        ? `Cómo te llegan a ti los avisos de ${teamName}. No cambia lo que reciben los demás.`
                        : 'Cómo te llegan a ti los avisos. No cambia lo que reciben los demás.'
                }
            >
                <SettingsSection
                    title="Por tipo de aviso"
                    description="Mientras no guardes una preferencia, se aplica la regla de tu equipo."
                >
                    {types.length === 0 ? (
                        <FormCard>
                            <EmptyState
                                className="py-6"
                                icon={Bell}
                                title="Aún no hay avisos que ajustar"
                                description="Cuando tu equipo empiece a recibir avisos, aquí podrás elegir por dónde te llega cada uno."
                            />
                        </FormCard>
                    ) : (
                        <FormCard className="gap-0 p-0">
                            <ul className="divide-y divide-border">
                                {types.map((type) => {
                                    const pref = byType.get(type);

                                    return (
                                        <PreferenceRow
                                            key={`${type}:${pref?.id ?? 'new'}:${pref ? pref.allowedChannels.join(',') : ''}:${pref?.muted ?? false}`}
                                            type={type}
                                            label={typeLabels[type] ?? type}
                                            initialChannels={
                                                pref?.allowedChannels ?? []
                                            }
                                            initialMuted={pref?.muted ?? false}
                                            configured={pref !== undefined}
                                            channelOptions={channelOptions}
                                        />
                                    );
                                })}
                            </ul>
                        </FormCard>
                    )}
                </SettingsSection>
            </SettingsPage>
        </>
    );
}
