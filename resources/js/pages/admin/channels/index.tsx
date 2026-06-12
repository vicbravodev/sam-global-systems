import { Head, router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface PlatformChannel {
    id: number;
    code: string;
    name: string;
    provider: string;
    channelType: string | null;
    isActive: boolean;
    configKeys: string[];
}

interface AdminChannelsIndexProps {
    channels: PlatformChannel[];
    channelTypes: string[];
}

const CONFIG_FIELDS: Record<string, { key: string; label: string }[]> = {
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
    voice: [
        { key: 'twilio_account_sid', label: 'Twilio Account SID' },
        { key: 'twilio_auth_token', label: 'Twilio Auth Token' },
        { key: 'from', label: 'Número de voz (E.164)' },
    ],
    push: [
        { key: 'firebase_credentials', label: 'Credenciales Firebase (JSON)' },
    ],
    slack: [{ key: 'slack_webhook_url', label: 'Webhook URL de Slack' }],
    webhook: [
        { key: 'url', label: 'URL destino' },
        { key: 'secret', label: 'Secreto HMAC' },
    ],
    email: [],
    web: [],
};

const providerFor = (type: string): string =>
    type === 'sms' || type === 'whatsapp' || type === 'voice'
        ? 'twilio'
        : type === 'push'
          ? 'firebase'
          : type === 'slack'
            ? 'slack'
            : type === 'webhook'
              ? 'webhook'
              : 'mail';

export default function AdminChannelsIndex({
    channels,
    channelTypes,
}: AdminChannelsIndexProps) {
    const [form, setForm] = useState({
        code: '',
        name: '',
        channelType: 'voice',
        config: {} as Record<string, string>,
    });

    const create = () => {
        router.post(
            '/admin/channels',
            {
                code: form.code,
                name: form.name,
                provider: providerFor(form.channelType),
                channel_type: form.channelType,
                config_json: form.config,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Canal de plataforma creado.');
                    setForm({
                        code: '',
                        name: '',
                        channelType: 'voice',
                        config: {},
                    });
                },
                onError: () =>
                    toast.error(
                        'No se pudo crear el canal (¿code duplicado?).',
                    ),
            },
        );
    };

    const toggleActive = (channel: PlatformChannel) =>
        router.put(
            `/admin/channels/${channel.id}`,
            { is_active: !channel.isActive },
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Canal actualizado.'),
                onError: () => toast.error('No se pudo actualizar.'),
            },
        );

    const destroy = (channel: PlatformChannel) => {
        if (
            !window.confirm(
                `Se eliminará el canal de plataforma «${channel.name}» para TODOS los tenants. ¿Continuar?`,
            )
        ) {
            return;
        }

        router.delete(`/admin/channels/${channel.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success('Canal eliminado.'),
            onError: () => toast.error('No se pudo eliminar.'),
        });
    };

    const fields = CONFIG_FIELDS[form.channelType] ?? [];

    return (
        <div className="flex h-full flex-col overflow-hidden">
            <Head title="Canales de plataforma" />

            <header className="flex shrink-0 items-center justify-between gap-3 border-b border-border bg-surface-1 px-5 py-3">
                <div className="flex items-center gap-3">
                    <h1 className="sam-h2 m-0">Canales de plataforma</h1>
                    <span className="sam-meta">
                        {channels.length} canales provistos por SAM
                    </span>
                </div>
            </header>

            <div className="flex-1 overflow-y-auto p-5">
                <div className="mb-5 flex flex-col gap-3 rounded-md border border-border bg-surface-1 p-4">
                    <span className="sam-meta">
                        Crear canal de plataforma — los tenants lo reciben
                        activo y solo pueden encenderlo/apagarlo; las
                        credenciales viven aquí.
                    </span>
                    <div className="flex flex-wrap items-end gap-2">
                        <div>
                            <Label className="sam-meta">Código</Label>
                            <Input
                                value={form.code}
                                onChange={(e) =>
                                    setForm({ ...form, code: e.target.value })
                                }
                                placeholder="sam_voice_mx"
                                className="w-44"
                            />
                        </div>
                        <div>
                            <Label className="sam-meta">Nombre</Label>
                            <Input
                                value={form.name}
                                onChange={(e) =>
                                    setForm({ ...form, name: e.target.value })
                                }
                                placeholder="Voz SAM México"
                                className="w-52"
                            />
                        </div>
                        <div>
                            <Label className="sam-meta">Tipo</Label>
                            <select
                                value={form.channelType}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        channelType: e.target.value,
                                        config: {},
                                    })
                                }
                                className="h-9 w-36 rounded-md border border-border bg-surface-1 px-2 text-sm"
                            >
                                {channelTypes.map((type) => (
                                    <option key={type} value={type}>
                                        {type}
                                    </option>
                                ))}
                            </select>
                        </div>
                        {fields.map((field) => (
                            <div key={field.key}>
                                <Label className="sam-meta">
                                    {field.label}
                                </Label>
                                <Input
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
                                    className="w-52"
                                />
                            </div>
                        ))}
                        <Button
                            onClick={create}
                            disabled={!form.code || !form.name}
                        >
                            Crear
                        </Button>
                    </div>
                </div>

                <div className="overflow-hidden rounded-md border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-surface-2 text-left">
                            <tr className="sam-meta">
                                <th className="px-3 py-2 font-medium">Canal</th>
                                <th className="px-3 py-2 font-medium">Tipo</th>
                                <th className="px-3 py-2 font-medium">
                                    Proveedor
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Config
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Estado
                                </th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {channels.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-3 py-6 text-center text-fg-3"
                                    >
                                        Sin canales de plataforma. Crea el
                                        primero arriba.
                                    </td>
                                </tr>
                            )}
                            {channels.map((channel) => (
                                <tr
                                    key={channel.id}
                                    className="border-t border-border"
                                >
                                    <td className="px-3 py-2">
                                        <span className="font-medium">
                                            {channel.name}
                                        </span>
                                        <span className="ml-2 font-mono text-2xs text-fg-3">
                                            {channel.code}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2 font-mono text-xs">
                                        {channel.channelType}
                                    </td>
                                    <td className="px-3 py-2">
                                        {channel.provider}
                                    </td>
                                    <td className="px-3 py-2 font-mono text-2xs text-fg-3">
                                        {channel.configKeys.join(', ') || '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        <span
                                            className={
                                                channel.isActive
                                                    ? 'text-health-ok'
                                                    : 'text-fg-3'
                                            }
                                        >
                                            {channel.isActive
                                                ? 'Activo'
                                                : 'Inactivo'}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <div className="flex justify-end gap-2">
                                            <Button
                                                size="sm"
                                                variant="outline"
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
                                                variant="outline"
                                                onClick={() => destroy(channel)}
                                            >
                                                <Trash2 size={13} />
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
