import { Head, router } from '@inertiajs/react';
import { Plus, Radio, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { BillingPill } from '@/components/sam/billing/panel';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import { ListPage } from '@/components/sam/list-page';
import { MetaChip } from '@/components/sam/meta-chip';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    destroy as destroyChannel,
    index as channelsIndex,
    store as storeChannel,
    update as updateChannel,
} from '@/routes/admin/channels';

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
    channelTypes: { value: string; label: string }[];
}

interface ConfigField {
    key: string;
    label: string;
    hint?: string;
    kind?: 'text' | 'secret' | 'json' | 'number';
}

// Las credenciales de Twilio son env de plataforma (TWILIO_*), nunca config
// de canal: un canal Twilio sólo sobreescribe valores no secretos; vacío =
// default de plataforma (TWILIO_SMS_FROM / _WHATSAPP_FROM / _VOICE_FROM).
const CONFIG_FIELDS: Record<string, ConfigField[]> = {
    sms: [
        {
            key: 'from',
            label: 'Emisor (opcional)',
            hint: 'Número E.164 o Messaging Service (MG…). Con MG, un envío con timeout no se puede rastrear en Twilio y cae al reintento normal.',
        },
    ],
    whatsapp: [
        {
            key: 'from',
            label: 'Emisor (opcional)',
            hint: 'Formato whatsapp:+52…',
        },
        {
            key: 'content_sid',
            label: 'Plantilla aprobada (Content SID)',
            hint: 'Plantilla con {{1}} asunto y {{2}} cuerpo. Sin ella se envía texto libre, que Twilio rechaza fuera de la ventana de 24 h.',
        },
    ],
    voice: [
        {
            key: 'from',
            label: 'Número de voz (opcional)',
            hint: 'E.164. Vacío = número de plataforma.',
        },
        {
            key: 'ring_timeout_seconds',
            label: 'Segundos de timbrado',
            hint: 'Por defecto 25.',
            kind: 'number',
        },
    ],
    push: [
        {
            key: 'firebase_credentials',
            label: 'Credenciales de Firebase (JSON)',
            kind: 'json',
        },
    ],
    slack: [
        { key: 'slack_webhook_url', label: 'Webhook de Slack', kind: 'secret' },
    ],
    webhook: [
        { key: 'url', label: 'URL destino' },
        { key: 'secret', label: 'Secreto HMAC', kind: 'secret' },
    ],
    email: [],
    web: [],
};

const PROVIDER_LABELS: Record<string, string> = {
    twilio: 'Twilio',
    firebase: 'Firebase',
    slack: 'Slack',
    webhook: 'Webhook',
    mail: 'Correo SAM',
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

const EMPTY_FORM = {
    code: '',
    name: '',
    channelType: 'voice',
    config: {} as Record<string, string>,
};

function CreateChannelSheet({
    open,
    onOpenChange,
    channelTypes,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    channelTypes: { value: string; label: string }[];
}) {
    const [form, setForm] = useState(EMPTY_FORM);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const fields = CONFIG_FIELDS[form.channelType] ?? [];

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const config = Object.fromEntries(
            Object.entries(form.config).filter(([, v]) => v.trim() !== ''),
        );

        router.post(
            storeChannel().url,
            {
                code: form.code,
                name: form.name,
                provider: providerFor(form.channelType),
                channel_type: form.channelType,
                config_json: Object.keys(config).length > 0 ? config : null,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    setForm(EMPTY_FORM);
                    setErrors({});
                    onOpenChange(false);
                },
                onError: setErrors,
            },
        );
    };

    const configError = Object.entries(errors).find(([k]) =>
        k.startsWith('config_json'),
    )?.[1];

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="flex w-full flex-col gap-0 p-0 sm:max-w-md">
                <form
                    onSubmit={submit}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <SheetHeader className="border-b border-border px-5 py-4">
                        <SheetTitle>Nuevo canal de plataforma</SheetTitle>
                        <SheetDescription>
                            Todos los clientes lo reciben activo y sólo pueden
                            encenderlo o apagarlo. Las credenciales de Twilio
                            viven en el entorno, nunca aquí.
                        </SheetDescription>
                    </SheetHeader>

                    <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-5 py-5">
                        <div className="grid gap-1.5">
                            <Label htmlFor="channel-type">Tipo</Label>
                            <Select
                                value={form.channelType}
                                onValueChange={(value) =>
                                    setForm({
                                        ...form,
                                        channelType: value,
                                        config: {},
                                    })
                                }
                            >
                                <SelectTrigger
                                    id="channel-type"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {channelTypes.map((type) => (
                                        <SelectItem
                                            key={type.value}
                                            value={type.value}
                                        >
                                            {type.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-xs text-fg-3">
                                Proveedor:{' '}
                                {PROVIDER_LABELS[
                                    providerFor(form.channelType)
                                ] ?? providerFor(form.channelType)}
                            </p>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="channel-name">Nombre</Label>
                            <Input
                                id="channel-name"
                                value={form.name}
                                onChange={(e) =>
                                    setForm({ ...form, name: e.target.value })
                                }
                                placeholder="Voz SAM México"
                                required
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="channel-code">Código</Label>
                            <Input
                                id="channel-code"
                                value={form.code}
                                onChange={(e) =>
                                    setForm({ ...form, code: e.target.value })
                                }
                                placeholder="sam_voice_mx"
                                className="font-mono"
                                required
                            />
                            <p className="text-xs text-fg-3">
                                Identificador único; no se puede cambiar.
                            </p>
                            <InputError message={errors.code} />
                        </div>

                        {fields.map((field) => {
                            const id = `channel-config-${field.key}`;
                            const value = form.config[field.key] ?? '';
                            const set = (v: string) =>
                                setForm({
                                    ...form,
                                    config: { ...form.config, [field.key]: v },
                                });

                            return (
                                <div key={field.key} className="grid gap-1.5">
                                    <Label htmlFor={id}>{field.label}</Label>
                                    {field.kind === 'json' ? (
                                        <Textarea
                                            id={id}
                                            value={value}
                                            onChange={(e) =>
                                                set(e.target.value)
                                            }
                                            rows={6}
                                            className="font-mono text-xs"
                                            spellCheck={false}
                                        />
                                    ) : (
                                        <Input
                                            id={id}
                                            type={
                                                field.kind === 'secret'
                                                    ? 'password'
                                                    : field.kind === 'number'
                                                      ? 'number'
                                                      : 'text'
                                            }
                                            autoComplete={
                                                field.kind === 'secret'
                                                    ? 'new-password'
                                                    : 'off'
                                            }
                                            value={value}
                                            onChange={(e) =>
                                                set(e.target.value)
                                            }
                                        />
                                    )}
                                    {field.hint ? (
                                        <p className="text-xs text-fg-3">
                                            {field.hint}
                                        </p>
                                    ) : null}
                                </div>
                            );
                        })}
                        <InputError message={configError} />
                    </div>

                    <SheetFooter className="flex-row justify-end gap-2 border-t border-border px-5 py-3">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                            disabled={saving}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={saving}>
                            {saving && <Spinner />}
                            Crear canal
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}

interface Pending {
    title: string;
    description: string;
    confirmLabel: string;
    tone: 'destructive' | 'default';
    run: () => Promise<void>;
}

function visit(method: 'put' | 'delete', url: string, data = {}) {
    return new Promise<void>((resolve) => {
        const options = { preserveScroll: true, onFinish: () => resolve() };

        if (method === 'delete') {
            router.delete(url, options);
        } else {
            router.put(url, data, options);
        }
    });
}

export default function AdminChannelsIndex({
    channels,
    channelTypes,
}: AdminChannelsIndexProps) {
    const [createOpen, setCreateOpen] = useState(false);
    const [pending, setPending] = useState<Pending | null>(null);
    const typeLabel = (value: string | null) =>
        channelTypes.find((t) => t.value === value)?.label ??
        value ??
        'Sin tipo';

    return (
        <>
            <Head title="Canales de plataforma" />
            <ListPage
                title="Canales"
                description="Por dónde SAM avisa a los clientes. Apagar un canal corta ese aviso para todos."
                meta={
                    <span className="text-xs text-fg-3">
                        <span className="font-medium text-fg-1">
                            {channels.filter((c) => c.isActive).length}
                        </span>{' '}
                        de {channels.length} activos
                    </span>
                }
                actions={
                    <Button size="sm" onClick={() => setCreateOpen(true)}>
                        <Plus className="size-3.5" />
                        Nuevo canal
                    </Button>
                }
            >
                <div className="min-h-0 flex-1 overflow-y-auto p-5">
                    <section className="max-w-5xl rounded-lg border border-border bg-surface-1">
                        {channels.length === 0 ? (
                            <EmptyState
                                icon={Radio}
                                title="Sin canales de plataforma"
                                description="Siémbralos con db:seed (PlatformChannelSeeder) o crea el primero."
                                action={
                                    <Button
                                        size="sm"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        <Plus className="size-3.5" />
                                        Nuevo canal
                                    </Button>
                                }
                            />
                        ) : (
                            <ul className="divide-y divide-border">
                                {channels.map((channel) => (
                                    <li
                                        key={channel.id}
                                        className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="flex flex-wrap items-center gap-1.5 text-sm font-medium">
                                                {channel.name}
                                                <span className="font-mono text-3xs font-normal text-fg-3">
                                                    {channel.code}
                                                </span>
                                            </p>
                                            <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                <MetaChip>
                                                    {typeLabel(
                                                        channel.channelType,
                                                    )}
                                                </MetaChip>
                                                <MetaChip>
                                                    {PROVIDER_LABELS[
                                                        channel.provider
                                                    ] ?? channel.provider}
                                                </MetaChip>
                                                {channel.configKeys.length >
                                                0 ? (
                                                    <span className="text-xs text-fg-3">
                                                        Configura:{' '}
                                                        {channel.configKeys.join(
                                                            ', ',
                                                        )}
                                                    </span>
                                                ) : (
                                                    <span className="text-xs text-fg-3">
                                                        Usa los defaults de
                                                        plataforma
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        <BillingPill
                                            tone={
                                                channel.isActive
                                                    ? 'ok'
                                                    : 'neutral'
                                            }
                                        >
                                            {channel.isActive
                                                ? 'Activo'
                                                : 'Apagado'}
                                        </BillingPill>
                                        <Switch
                                            checked={channel.isActive}
                                            aria-label={`${channel.isActive ? 'Apagar' : 'Encender'} ${channel.name}`}
                                            onCheckedChange={(next) =>
                                                setPending({
                                                    title: `${next ? 'Encender' : 'Apagar'} ${channel.name}`,
                                                    description: next
                                                        ? 'Todos los clientes vuelven a recibir avisos por este canal (salvo los que lo apagaron).'
                                                        : 'Ningún cliente recibirá avisos por este canal, incluidos los de pánico, hasta que lo enciendas.',
                                                    confirmLabel: next
                                                        ? 'Encender'
                                                        : 'Apagar',
                                                    tone: next
                                                        ? 'default'
                                                        : 'destructive',
                                                    run: () =>
                                                        visit(
                                                            'put',
                                                            updateChannel(
                                                                channel.id,
                                                            ).url,
                                                            {
                                                                is_active: next,
                                                            },
                                                        ),
                                                })
                                            }
                                        />
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            className="size-8 text-fg-3 hover:text-destructive"
                                            aria-label={`Eliminar canal ${channel.name}`}
                                            onClick={() =>
                                                setPending({
                                                    title: `Eliminar ${channel.name}`,
                                                    description:
                                                        'Se elimina para todos los clientes. No se puede deshacer.',
                                                    confirmLabel:
                                                        'Eliminar canal',
                                                    tone: 'destructive',
                                                    run: () =>
                                                        visit(
                                                            'delete',
                                                            destroyChannel(
                                                                channel.id,
                                                            ).url,
                                                        ),
                                                })
                                            }
                                        >
                                            <Trash2 className="size-3.5" />
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </ListPage>

            <CreateChannelSheet
                open={createOpen}
                onOpenChange={setCreateOpen}
                channelTypes={channelTypes}
            />

            <ConfirmDialog
                open={pending !== null}
                title={pending?.title ?? ''}
                description={pending?.description ?? ''}
                confirmLabel={pending?.confirmLabel}
                tone={pending?.tone}
                onOpenChange={(open) => !open && setPending(null)}
                onConfirm={async () => {
                    await pending?.run();
                    setPending(null);
                }}
            />
        </>
    );
}

AdminChannelsIndex.layout = {
    breadcrumbs: [{ title: 'Canales', href: channelsIndex().url }],
};
