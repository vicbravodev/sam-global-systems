import { Form, Head } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { edit } from '@/routes/security';
import { disable, enable } from '@/routes/two-factor';

type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

export default function Security({
    canManageTwoFactor = false,
    requiresConfirmation = false,
    twoFactorEnabled = false,
}: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        clearTwoFactorAuthData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState<boolean>(false);
    const prevTwoFactorEnabled = useRef(twoFactorEnabled);

    useEffect(() => {
        if (prevTwoFactorEnabled.current && !twoFactorEnabled) {
            clearTwoFactorAuthData();
        }

        prevTwoFactorEnabled.current = twoFactorEnabled;
    }, [twoFactorEnabled, clearTwoFactorAuthData]);

    return (
        <>
            <Head title="Configuración de seguridad" />

            <h1 className="sr-only">Configuración de seguridad</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Actualizar contraseña"
                    description="Asegúrate de que tu cuenta use una contraseña larga y aleatoria para mantenerse segura"
                />

                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                    className="space-y-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="current_password">
                                    Contraseña actual
                                </Label>

                                <PasswordInput
                                    id="current_password"
                                    ref={currentPasswordInput}
                                    name="current_password"
                                    className="mt-1 block w-full"
                                    autoComplete="current-password"
                                    placeholder="Contraseña actual"
                                />

                                <InputError message={errors.current_password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    Nueva contraseña
                                </Label>

                                <PasswordInput
                                    id="password"
                                    ref={passwordInput}
                                    name="password"
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    placeholder="Nueva contraseña"
                                />

                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Confirmar contraseña
                                </Label>

                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    placeholder="Confirmar contraseña"
                                />

                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-password-button"
                                >
                                    Guardar contraseña
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            {canManageTwoFactor && (
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Autenticación de dos factores"
                        description="Gestiona la configuración de tu autenticación de dos factores"
                    />
                    {twoFactorEnabled ? (
                        <div className="flex flex-col items-start justify-start space-y-4">
                            <p className="text-sm text-muted-foreground">
                                Durante el inicio de sesión se te pedirá un pin
                                seguro y aleatorio, que puedes obtener desde la
                                aplicación compatible con TOTP en tu teléfono.
                            </p>

                            <div className="relative inline">
                                <Form {...disable.form()}>
                                    {({ processing }) => (
                                        <Button
                                            variant="destructive"
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Desactivar 2FA
                                        </Button>
                                    )}
                                </Form>
                            </div>

                            <TwoFactorRecoveryCodes
                                recoveryCodesList={recoveryCodesList}
                                fetchRecoveryCodes={fetchRecoveryCodes}
                                errors={errors}
                            />
                        </div>
                    ) : (
                        <div className="flex flex-col items-start justify-start space-y-4">
                            <p className="text-sm text-muted-foreground">
                                Cuando actives la autenticación de dos factores,
                                se te pedirá un pin seguro durante el inicio de
                                sesión. Este pin se puede obtener desde una
                                aplicación compatible con TOTP en tu teléfono.
                            </p>

                            <div>
                                {hasSetupData ? (
                                    <Button
                                        onClick={() => setShowSetupModal(true)}
                                    >
                                        <ShieldCheck />
                                        Continuar configuración
                                    </Button>
                                ) : (
                                    <Form
                                        {...enable.form()}
                                        onSuccess={() =>
                                            setShowSetupModal(true)
                                        }
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Activar 2FA
                                            </Button>
                                        )}
                                    </Form>
                                )}
                            </div>
                        </div>
                    )}

                    <TwoFactorSetupModal
                        isOpen={showSetupModal}
                        onClose={() => setShowSetupModal(false)}
                        requiresConfirmation={requiresConfirmation}
                        twoFactorEnabled={twoFactorEnabled}
                        qrCodeSvg={qrCodeSvg}
                        manualSetupKey={manualSetupKey}
                        clearSetupData={clearSetupData}
                        fetchSetupData={fetchSetupData}
                        errors={errors}
                    />
                </div>
            )}
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'Configuración de seguridad',
            href: edit(),
        },
    ],
};
