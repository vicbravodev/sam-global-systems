import { ImageUp } from 'lucide-react';
import { useState } from 'react';
import { Field, FormCard } from '@/components/sam/field';
import {
    FormActions,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { postFormData, putJson } from '@/lib/sam-fetch';
import { submit } from '@/lib/submit';
import { CONFIG_SUBMIT, useTeamBase } from './shared';
import type { BrandingProp } from './types';

function ColorInput({
    id,
    value,
    onChange,
    disabled,
    invalid,
}: {
    id: string;
    value: string;
    onChange: (value: string) => void;
    disabled: boolean;
    invalid: boolean;
}) {
    return (
        <div className="flex items-center gap-2">
            <input
                type="color"
                aria-label="Elegir color"
                value={/^#[0-9a-f]{6}$/i.test(value) ? value : '#000000'}
                disabled={disabled}
                onChange={(e) => onChange(e.target.value)}
                className="h-9 w-12 cursor-pointer rounded-md border border-border bg-surface-1 p-1 disabled:cursor-not-allowed"
            />
            <Input
                id={id}
                value={value}
                disabled={disabled}
                aria-invalid={invalid}
                onChange={(e) => onChange(e.target.value)}
                className="w-28 font-mono uppercase"
                maxLength={7}
            />
        </div>
    );
}

export function BrandingSection({
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
    const [errors, setErrors] = useState<Record<string, string>>({});

    const save = async () => {
        if (base === null || saving) {
            return;
        }

        setErrors({});
        setSaving(true);

        const result = await submit(
            putJson(`${base}/branding`, {
                display_name:
                    form.display_name === '' ? null : form.display_name,
                primary_color: form.primary_color,
                secondary_color: form.secondary_color,
                email_signature:
                    form.email_signature === '' ? null : form.email_signature,
            }),
            'Marca guardada.',
            CONFIG_SUBMIT,
        );

        if (!result.ok) {
            setErrors(result.fieldErrors);
        }

        setSaving(false);
    };

    const uploadLogo = async (file: File) => {
        if (base === null) {
            return;
        }

        setUploading(true);

        const body = new FormData();
        body.append('logo', file);

        try {
            await submit(
                postFormData(`${base}/branding/logo`, body),
                'Logo actualizado.',
                {
                    forbiddenMessage:
                        'No tienes permisos para cambiar la marca.',
                    errorMessage: 'No se pudo subir el logo.',
                },
            );
        } finally {
            setUploading(false);
        }
    };

    return (
        <SettingsSection
            title="Identidad de tu empresa"
            description="Así se presentan los correos y reportes que SAM envía en tu nombre."
        >
            <FormCard>
                <Field
                    label="Logo"
                    help="PNG o JPG, idealmente cuadrado y con fondo transparente."
                    htmlFor="tc-logo-upload"
                >
                    <div className="flex items-center gap-3">
                        {branding.logoUrl ? (
                            <img
                                src={branding.logoUrl}
                                alt="Logo de la empresa"
                                className="size-14 rounded-md border border-border bg-surface-2 object-contain"
                            />
                        ) : (
                            <div className="grid size-14 place-items-center rounded-md border border-dashed border-border text-center text-3xs text-fg-3">
                                Sin logo
                            </div>
                        )}
                        {canManage ? (
                            <Button
                                asChild
                                size="sm"
                                variant="outline"
                                disabled={uploading}
                            >
                                <label
                                    htmlFor="tc-logo-upload"
                                    className="cursor-pointer"
                                >
                                    {uploading ? (
                                        <Spinner className="size-3.5" />
                                    ) : (
                                        <ImageUp className="size-3.5" />
                                    )}
                                    {uploading
                                        ? 'Subiendo…'
                                        : branding.logoUrl
                                          ? 'Cambiar logo'
                                          : 'Subir logo'}
                                    <input
                                        id="tc-logo-upload"
                                        type="file"
                                        accept="image/*"
                                        className="sr-only"
                                        disabled={uploading}
                                        onChange={(e) => {
                                            const file = e.target.files?.[0];

                                            if (file) {
                                                void uploadLogo(file);
                                            }
                                        }}
                                    />
                                </label>
                            </Button>
                        ) : null}
                    </div>
                </Field>

                <Field
                    error={errors.display_name}
                    label="Nombre para mostrar"
                    help="Cómo firma SAM los avisos. Si lo dejas vacío, se usa el nombre de tu cuenta."
                    htmlFor="tc-display-name"
                >
                    <Input
                        id="tc-display-name"
                        value={form.display_name}
                        disabled={!canManage}
                        aria-invalid={Boolean(errors.display_name)}
                        onChange={(e) =>
                            setForm({ ...form, display_name: e.target.value })
                        }
                    />
                </Field>

                <Field
                    error={errors.primary_color}
                    label="Color principal"
                    help="Botones y encabezados de correos y reportes."
                    htmlFor="tc-primary-color"
                >
                    <ColorInput
                        id="tc-primary-color"
                        value={form.primary_color}
                        disabled={!canManage}
                        invalid={Boolean(errors.primary_color)}
                        onChange={(value) =>
                            setForm({ ...form, primary_color: value })
                        }
                    />
                </Field>

                <Field
                    error={errors.secondary_color}
                    label="Color secundario"
                    help="Fondos y detalles de apoyo."
                    htmlFor="tc-secondary-color"
                >
                    <ColorInput
                        id="tc-secondary-color"
                        value={form.secondary_color}
                        disabled={!canManage}
                        invalid={Boolean(errors.secondary_color)}
                        onChange={(value) =>
                            setForm({ ...form, secondary_color: value })
                        }
                    />
                </Field>

                <Field
                    error={errors.email_signature}
                    label="Firma de correo"
                    help="Se añade al final de cada correo que SAM envía."
                    htmlFor="tc-email-signature"
                >
                    <textarea
                        id="tc-email-signature"
                        value={form.email_signature}
                        disabled={!canManage}
                        aria-invalid={Boolean(errors.email_signature)}
                        onChange={(e) =>
                            setForm({
                                ...form,
                                email_signature: e.target.value,
                            })
                        }
                        rows={3}
                        placeholder="Centro de monitoreo · Tel. 55 0000 0000"
                        className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm text-fg-1 shadow-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
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
