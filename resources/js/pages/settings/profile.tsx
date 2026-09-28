import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import PhoneVerificationController from '@/actions/App/Http/Controllers/Settings/PhoneVerificationController';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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

    return (
        <>
            <Head title="Configuración de perfil" />

            <h1 className="sr-only">Configuración de perfil</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Información del perfil"
                    description="Actualiza tu nombre, correo y teléfono"
                />

                {(!phoneVerified ||
                    (mustVerifyEmail &&
                        auth.user.email_verified_at === null)) && (
                    <div
                        role="status"
                        className="rounded-lg border border-severity-medium/40 bg-severity-medium/10 px-4 py-3 text-sm text-fg-2"
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

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nombre</Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder="Nombre completo"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    Correo electrónico
                                </Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    value={email}
                                    onChange={(e) => setEmail(e.target.value)}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder="Correo electrónico"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="phone">Teléfono celular</Label>

                                <Input
                                    id="phone"
                                    type="tel"
                                    className="mt-1 block w-full"
                                    defaultValue={savedPhone}
                                    name="phone"
                                    autoComplete="tel"
                                    inputMode="tel"
                                    placeholder="+5215555550123"
                                />

                                <p className="text-xs text-muted-foreground">
                                    Formato internacional, con lada de país (+52
                                    para México).
                                </p>

                                <InputError
                                    className="mt-2"
                                    message={errors.phone}
                                />
                            </div>

                            {emailChanged && (
                                <div className="grid gap-2">
                                    <Label htmlFor="current_password">
                                        Contraseña actual
                                    </Label>

                                    <PasswordInput
                                        id="current_password"
                                        name="current_password"
                                        className="mt-1 block w-full"
                                        required
                                        autoComplete="current-password"
                                        placeholder="Confirma tu contraseña para cambiar el correo"
                                    />

                                    <InputError
                                        className="mt-2"
                                        message={errors.current_password}
                                    />
                                </div>
                            )}

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            Tu dirección de correo electrónico
                                            no está verificada.{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                Haz clic aquí para reenviar el
                                                correo de verificación.
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div className="mt-2 text-sm font-medium text-health-ok">
                                                Se ha enviado un nuevo enlace de
                                                verificación a tu dirección de
                                                correo electrónico.
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    Guardar
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            {savedPhone !== '' && (
                <PhoneVerification
                    phone={savedPhone}
                    verified={phoneVerified}
                    status={status}
                />
            )}

            <DeleteUser />
        </>
    );
}

function PhoneVerification({
    phone,
    verified,
    status,
}: {
    phone: string;
    verified: boolean;
    status?: string;
}) {
    if (verified) {
        return (
            <div className="space-y-2">
                <Heading
                    variant="small"
                    title="Teléfono verificado"
                    description={`${phone} recibe las llamadas, SMS y WhatsApp de emergencia.`}
                />
            </div>
        );
    }

    const codeSent = status === 'phone-otp-sent';

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Verifica tu teléfono"
                description={`Te enviamos un código por SMS a ${phone}.`}
            />

            <Form
                {...PhoneVerificationController.send.form()}
                options={{ preserveScroll: true }}
            >
                {({ processing, errors }) => (
                    <div className="grid gap-2">
                        <div>
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                                data-test="send-phone-code-button"
                            >
                                {codeSent ? 'Reenviar código' : 'Enviar código'}
                            </Button>
                        </div>
                        <InputError message={errors.phone} />
                        {codeSent && (
                            <p className="text-sm font-medium text-health-ok">
                                Código enviado. Revisa tus SMS.
                            </p>
                        )}
                    </div>
                )}
            </Form>

            {codeSent && (
                <Form
                    {...PhoneVerificationController.verify.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="code">
                                    Código de 6 dígitos
                                </Label>
                                <Input
                                    id="code"
                                    name="code"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    maxLength={6}
                                    required
                                    className="mt-1 block w-40"
                                    placeholder="123456"
                                />
                                <InputError message={errors.code} />
                            </div>
                            <Button
                                disabled={processing}
                                data-test="verify-phone-button"
                            >
                                Verificar
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Configuración de perfil',
            href: edit(),
        },
    ],
};
