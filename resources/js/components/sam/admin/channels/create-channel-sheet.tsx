import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { NOTIFICATION_PROVIDER_LABELS } from '@/components/sam/admin/copy';
import { Button } from '@/components/ui/button';
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
import { Textarea } from '@/components/ui/textarea';
import { store as storeChannel } from '@/routes/admin/channels';
import { CONFIG_FIELDS, providerFor } from './lib';
import type { ChannelTypeOption } from './types';

const EMPTY_FORM = {
    code: '',
    name: '',
    channelType: 'voice',
    config: {} as Record<string, string>,
};

export function CreateChannelSheet({
    open,
    onOpenChange,
    channelTypes,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    channelTypes: ChannelTypeOption[];
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
                                {NOTIFICATION_PROVIDER_LABELS[
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
