import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Field, FormCard } from '@/components/sam/field';
import { PhoneVerification } from '@/components/sam/settings/phone-verification';
import {
    FormActions,
    SettingsPage,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

export default function Profile({
    mustVerifyEmail,
    status,
    phoneVerified,
}: {
    mustVerifyEmail: boolean;
    status?: string;
    phoneVerified: boolean;
}) {
    const { auth } = usePage().props;
    const savedPhone = (auth.user.phone ?? '').trim();
    const [email, setEmail] = useState(auth.user.email);
    // Cambiar el correo (identidad de login) exige la contraseña actual.
    const emailChanged =
        email.trim().toLowerCase() !== auth.user.email.toLowerCase();
    const unverified = mustVerifyEmail && auth.user.email_verified_at === null;

    return (
        <>
            <Head title="Perfil" />
            <SettingsPage
                title="Perfil"
                description="Tu nombre, el correo con el que entras a SAM y el teléfono donde te avisamos."
            >
                {(!phoneVerified || unverified) && (
                    <div
                        role="status"
                        className="max-w-3xl rounded-lg border border-severity-medium/40 bg-severity-medium/10 px-4 py-3 text-sm text-fg-2"
                    >
                        <p className="font-medium text-fg-1">
                            Verifica tu teléfono y tu correo
                        </p>
                        <p className="mt-1">
                            SAM te llama, te manda SMS o WhatsApp y te escribe
                            por correo cuando hay una emergencia de tu flota.
                            Sin teléfono verificado no podemos localizarte.
                        </p>
                    </div>
                )}

                <SettingsSection
                    title="Datos personales"
                    description="Tu nombre aparece en los incidentes que atiendes y en el historial."
                >
                    <Form
                        {...ProfileController.update.form()}
                        options={{
                            preserveScroll: true,
                        }}
                    >
                        {({ processing, errors, recentlySuccessful }) => (
                            <FormCard>
                                <Field label="Nombre" htmlFor="name">
                                    <Input
                                        id="name"
                                        defaultValue={auth.user.name}
                                        name="name"
                                        required
                                        autoComplete="name"
                                        placeholder="Nombre completo"
                                    />
                                    <InputError message={errors.name} />
                                </Field>

                                <Field
                                    label="Correo electrónico"
                                    help="Lo usas para entrar y ahí te llegan los avisos por correo."
                                    htmlFor="email"
                                >
                                    <Input
                                        id="email"
                                        type="email"
                                        value={email}
                                        onChange={(e) =>
                                            setEmail(e.target.value)
                                        }
                                        name="email"
                                        required
                                        autoComplete="username"
                                        placeholder="Correo electrónico"
                                    />
                                    <InputError message={errors.email} />
                                    {unverified ? (
                                        <p className="text-xs text-fg-3">
                                            Tu correo aún no está verificado.{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-fg-1 underline underline-offset-4"
                                            >
                                                Reenviar el correo de
                                                verificación
                                            </Link>
                                        </p>
                                    ) : null}
                                    {status === 'verification-link-sent' ? (
                                        <p className="text-xs font-medium text-health-ok">
                                            Te enviamos un nuevo enlace de
                                            verificación.
                                        </p>
                                    ) : null}
                                </Field>

                                <Field
                                    label="Teléfono celular"
                                    help="Formato internacional con lada de país (+52 para México). Ahí te llaman y te escriben en una emergencia."
                                    htmlFor="phone"
                                >
                                    <Input
                                        id="phone"
                                        type="tel"
                                        defaultValue={savedPhone}
                                        name="phone"
                                        autoComplete="tel"
                                        inputMode="tel"
                                        placeholder="+5215555550123"
                                    />
                                    <InputError message={errors.phone} />
                                </Field>

                                {emailChanged ? (
                                    <Field
                                        label="Contraseña actual"
                                        help="Por seguridad, confírmala para cambiar el correo."
                                        htmlFor="current_password"
                                    >
                                        <PasswordInput
                                            id="current_password"
                                            name="current_password"
                                            required
                                            autoComplete="current-password"
                                            placeholder="Tu contraseña"
                                        />
                                        <InputError
                                            message={errors.current_password}
                                        />
                                    </Field>
                                ) : null}

                                <FormActions
                                    hint={
                                        recentlySuccessful
                                            ? 'Cambios guardados.'
                                            : undefined
                                    }
                                >
                                    <Button
                                        size="sm"
                                        disabled={processing}
                                        data-test="update-profile-button"
                                    >
                                        Guardar cambios
                                    </Button>
                                </FormActions>
                            </FormCard>
                        )}
                    </Form>
                </SettingsSection>

                {savedPhone !== '' && (
                    <PhoneVerification
                        phone={savedPhone}
                        verified={phoneVerified}
                        status={status}
                    />
                )}

                <div className="max-w-3xl">
                    <DeleteUser />
                </div>
            </SettingsPage>
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Perfil',
            href: edit(),
        },
    ],
};
