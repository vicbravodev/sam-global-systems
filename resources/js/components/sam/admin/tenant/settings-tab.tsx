import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import type { Tenant, Pending } from '@/components/sam/admin/tenant/types';
import { visit } from '@/components/sam/admin/tenant/visit';
import { EntityAvatar } from '@/components/sam/entity-avatar';
import { FormField } from '@/components/sam/form-field';
import { Panel } from '@/components/sam/panel';
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
import { Spinner } from '@/components/ui/spinner';
import { TENANT_TIMEZONES } from '@/lib/timezones';
import {
    destroy as destroyTenant,
    update as updateTenant,
} from '@/routes/admin/tenants';

export function SettingsTab({
    tenant,
    confirm,
}: {
    tenant: Tenant;
    confirm: (p: Pending) => void;
}) {
    const [form, setForm] = useState({
        name: tenant.name,
        display_name: tenant.branding.displayName ?? '',
        primary_color: tenant.branding.primaryColor ?? '',
        logo_url: tenant.branding.logoUrl ?? '',
        timezone: tenant.timezone ?? '',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [deleteText, setDeleteText] = useState('');
    const [brokenLogo, setBrokenLogo] = useState<string | null>(null);
    const logoBroken = brokenLogo !== null && brokenLogo === form.logo_url;

    const set = (key: keyof typeof form) => (value: string) =>
        setForm((prev) => ({ ...prev, [key]: value }));

    const save = (e: FormEvent) => {
        e.preventDefault();
        router.put(
            updateTenant(tenant.slug).url,
            {
                name: form.name,
                display_name: form.display_name || null,
                primary_color: form.primary_color || null,
                logo_url: form.logo_url || null,
                timezone: form.timezone || null,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => setErrors({}),
                onError: setErrors,
            },
        );
    };

    const colorValid = /^#[0-9a-fA-F]{6}$/.test(form.primary_color);

    return (
        <div className="grid max-w-3xl gap-4">
            <Panel
                size="lg"
                bodyClassName="p-4"
                title="Identidad"
                description="Cómo se llama y se ve este cliente dentro de SAM."
            >
                <form onSubmit={save} className="grid gap-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            label="Nombre"
                            htmlFor="tenant-name"
                            error={errors.name}
                        >
                            <Input
                                id="tenant-name"
                                value={form.name}
                                onChange={(e) => set('name')(e.target.value)}
                                required
                            />
                        </FormField>
                        <FormField
                            label="Nombre visible (marca)"
                            htmlFor="display-name"
                            error={errors.display_name}
                        >
                            <Input
                                id="display-name"
                                value={form.display_name}
                                onChange={(e) =>
                                    set('display_name')(e.target.value)
                                }
                                placeholder={form.name}
                            />
                        </FormField>
                        <FormField
                            label="Zona horaria"
                            htmlFor="tenant-tz"
                            error={errors.timezone}
                        >
                            <Select
                                value={form.timezone}
                                onValueChange={set('timezone')}
                            >
                                <SelectTrigger
                                    id="tenant-tz"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Sin definir (UTC)" />
                                </SelectTrigger>
                                <SelectContent>
                                    {TENANT_TIMEZONES.map((tz) => (
                                        <SelectItem
                                            key={tz.value}
                                            value={tz.value}
                                        >
                                            {tz.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FormField>
                        <FormField
                            label="Color principal"
                            htmlFor="primary-color"
                            error={errors.primary_color}
                        >
                            <div className="flex items-center gap-2">
                                <input
                                    type="color"
                                    aria-label="Elegir color principal"
                                    value={
                                        colorValid
                                            ? form.primary_color
                                            : '#2563eb'
                                    }
                                    onChange={(e) =>
                                        set('primary_color')(e.target.value)
                                    }
                                    className="size-9 shrink-0 cursor-pointer rounded-md border border-border bg-transparent p-0.5"
                                />
                                <Input
                                    id="primary-color"
                                    value={form.primary_color}
                                    onChange={(e) =>
                                        set('primary_color')(e.target.value)
                                    }
                                    placeholder="#2563eb"
                                    className="font-mono"
                                    maxLength={7}
                                />
                            </div>
                        </FormField>
                        <FormField
                            label="URL del logo"
                            htmlFor="logo-url"
                            error={errors.logo_url}
                            className="sm:col-span-2"
                        >
                            <div className="flex items-center gap-3">
                                <div className="grid size-12 shrink-0 place-items-center overflow-hidden rounded-md border border-border bg-surface-2">
                                    {form.logo_url && !logoBroken ? (
                                        <img
                                            src={form.logo_url}
                                            alt="Vista previa del logo"
                                            decoding="async"
                                            className="size-full object-contain"
                                            onError={() =>
                                                setBrokenLogo(form.logo_url)
                                            }
                                        />
                                    ) : (
                                        <EntityAvatar
                                            name={form.name}
                                            shape="square"
                                            size={32}
                                        />
                                    )}
                                </div>
                                <Input
                                    id="logo-url"
                                    value={form.logo_url}
                                    onChange={(e) =>
                                        set('logo_url')(e.target.value)
                                    }
                                    placeholder="https://…/logo.svg"
                                />
                            </div>
                            {logoBroken ? (
                                <p className="text-xs text-severity-medium">
                                    No se pudo cargar la imagen de esa URL.
                                </p>
                            ) : null}
                        </FormField>
                    </div>
                    <div className="flex justify-end">
                        <Button type="submit" size="sm" disabled={saving}>
                            {saving && <Spinner />}
                            Guardar cambios
                        </Button>
                    </div>
                </form>
            </Panel>

            {tenant.isPersonal ? null : (
                <section className="rounded-lg border border-destructive/30 bg-destructive/5">
                    <header className="border-b border-destructive/20 px-4 py-3">
                        <h2 className="sam-h3 text-destructive">
                            Eliminar cliente
                        </h2>
                        <p className="mt-0.5 text-xs text-fg-3">
                            Su equipo pierde el acceso y sus integraciones dejan
                            de ingerir. Los datos se conservan (baja lógica).
                        </p>
                    </header>
                    <form
                        className="flex flex-col gap-3 p-4 sm:flex-row sm:items-end"
                        onSubmit={(e) => {
                            e.preventDefault();
                            confirm({
                                title: `Eliminar a ${tenant.name}`,
                                description:
                                    'El cliente queda dado de baja y desaparece de la lista. Esta acción no se deshace desde la consola.',
                                confirmLabel: 'Eliminar cliente',
                                tone: 'destructive',
                                run: () =>
                                    visit(
                                        'delete',
                                        destroyTenant(tenant.slug).url,
                                    ),
                            });
                        }}
                    >
                        <div className="grid flex-1 gap-1.5">
                            <Label htmlFor="delete-confirm">
                                Escribe{' '}
                                <span className="font-mono">{tenant.slug}</span>{' '}
                                para confirmar
                            </Label>
                            <Input
                                id="delete-confirm"
                                value={deleteText}
                                onChange={(e) => setDeleteText(e.target.value)}
                                placeholder={tenant.slug}
                                autoComplete="off"
                            />
                        </div>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={deleteText !== tenant.slug}
                        >
                            Eliminar cliente
                        </Button>
                    </form>
                </section>
            )}
        </div>
    );
}
