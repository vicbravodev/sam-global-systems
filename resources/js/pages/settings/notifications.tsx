import { Head, router } from '@inertiajs/react';
import { BellOff, Check } from 'lucide-react';
import { useMemo, useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
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
    initialChannels,
    initialMuted,
    configured,
    channelOptions,
}: {
    type: string;
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

    return (
        <div className="rounded-md border border-border p-4">
            <div className="flex items-center justify-between gap-2">
                <div className="flex min-w-0 items-center gap-2">
                    <span className="truncate font-mono text-[12px] font-medium">
                        {type}
                    </span>
                    {!configured && (
                        <span className="shrink-0 rounded-full bg-muted px-2 py-0.5 text-[10px] text-muted-foreground">
                            Sin configurar
                        </span>
                    )}
                </div>
                <Button
                    size="sm"
                    onClick={save}
                    disabled={!dirty || saving || channels.length === 0}
                >
                    <Check className="h-3.5 w-3.5" />
                    Guardar
                </Button>
            </div>

            <div className="mt-3 flex flex-wrap gap-x-5 gap-y-2">
                {channelOptions.map((option) => (
                    <label
                        key={option.value}
                        className="flex cursor-pointer items-center gap-2 text-[12px]"
                    >
                        <Checkbox
                            checked={channels.includes(option.value)}
                            onCheckedChange={() => toggleChannel(option.value)}
                        />
                        {option.label}
                    </label>
                ))}
            </div>

            <label
                className={cn(
                    'mt-3 flex w-fit cursor-pointer items-center gap-2 text-[12px]',
                    muted && 'text-muted-foreground',
                )}
            >
                <Checkbox
                    checked={muted}
                    onCheckedChange={(checked) => setMuted(checked === true)}
                />
                <BellOff className="h-3.5 w-3.5" />
                Silenciar (prioridad baja y normal)
            </label>

            {channels.length === 0 && (
                <p className="mt-2 text-[11px] text-muted-foreground">
                    Selecciona al menos un canal para guardar.
                </p>
            )}
        </div>
    );
}

export default function NotificationsSettings({
    preferences = [],
    knownTypes = [],
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

        return [...all].sort();
    }, [knownTypes, preferences]);

    return (
        <>
            <Head title="Preferencias de notificación" />

            <h1 className="sr-only">Preferencias de notificación</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Preferencias de notificación"
                    description={
                        teamName
                            ? `Elige por qué canales recibir cada tipo de notificación en ${teamName}. Sin preferencia, aplica la política del equipo.`
                            : 'Elige por qué canales recibir cada tipo de notificación. Sin preferencia, aplica la política del equipo.'
                    }
                />

                <div className="space-y-3">
                    {types.map((type) => {
                        const pref = byType.get(type);

                        return (
                            <PreferenceRow
                                key={`${type}:${pref?.id ?? 'new'}:${pref ? pref.allowedChannels.join(',') : ''}:${pref?.muted ?? false}`}
                                type={type}
                                initialChannels={pref?.allowedChannels ?? []}
                                initialMuted={pref?.muted ?? false}
                                configured={pref !== undefined}
                                channelOptions={channelOptions}
                            />
                        );
                    })}
                </div>
            </div>
        </>
    );
}
