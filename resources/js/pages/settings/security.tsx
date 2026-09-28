import { Form, Head } from '@inertiajs/react';
import { ShieldCheck, ShieldOff } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Field, FormCard } from '@/components/sam/field';
import { StatePill } from '@/components/sam/settings/controls';
import {
    FormActions,
    SettingsPage,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Button } from '@/components/ui/button';
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
            <Head title="Seguridad" />
            <SettingsPage
                title="Seguridad"
                description="Tu contraseña y la verificación en dos pasos para entrar a SAM."
            >
                <SettingsSection
                    title="Contraseña"
                    description="Usa una contraseña larga que no utilices en otros sitios."
                >
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
                    >
                        {({ errors, processing, recentlySuccessful }) => (
                            <FormCard>
                                <Field
                                    label="Contraseña actual"
                                    htmlFor="current_password"
                                >
                                    <PasswordInput
                                        id="current_password"
                                        ref={currentPasswordInput}
                                        name="current_password"
                                        autoComplete="current-password"
                                        placeholder="Contraseña actual"
                                    />
                                    <InputError
                                        message={errors.current_password}
                                    />
                                </Field>

                                <Field
                                    label="Nueva contraseña"
                                    htmlFor="password"
                                >
                                    <PasswordInput
                                        id="password"
                                        ref={passwordInput}
                                        name="password"
                                        autoComplete="new-password"
                                        placeholder="Nueva contraseña"
                                    />
                                    <InputError message={errors.password} />
                                </Field>

                                <Field
                                    label="Repite la nueva contraseña"
                                    htmlFor="password_confirmation"
                                >
                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        autoComplete="new-password"
                                        placeholder="Confirmar contraseña"
                                    />
                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </Field>

                                <FormActions
                                    hint={
                                        recentlySuccessful
                                            ? 'Contraseña actualizada.'
                                            : undefined
                                    }
                                >
                                    <Button
                                        size="sm"
                                        disabled={processing}
                                        data-test="update-password-button"
                                    >
                                        Guardar contraseña
                                    </Button>
                                </FormActions>
                            </FormCard>
                        )}
                    </Form>
                </SettingsSection>

                {canManageTwoFactor && (
                    <SettingsSection
                        title="Verificación en dos pasos"
                        description="Además de tu contraseña, SAM te pedirá un código de tu teléfono al entrar."
                        actions={
                            <StatePill
                                on={twoFactorEnabled}
                                onLabel="Activada"
                                offLabel="Desactivada"
                            />
                        }
                    >
                        <FormCard>
                            {twoFactorEnabled ? (
                                <>
                                    <p className="text-sm text-fg-2">
                                        Al iniciar sesión te pediremos el código
                                        de tu app de autenticación (Google
                                        Authenticator, 1Password, Authy…).
                                    </p>

                                    <TwoFactorRecoveryCodes
                                        recoveryCodesList={recoveryCodesList}
                                        fetchRecoveryCodes={fetchRecoveryCodes}
                                        errors={errors}
                                    />

                                    <FormActions hint="Desactivarla deja tu cuenta protegida sólo con la contraseña.">
                                        <Form {...disable.form()}>
                                            {({ processing }) => (
                                                <Button
                                                    variant="destructive"
                                                    size="sm"
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    <ShieldOff />
                                                    Desactivar
                                                </Button>
                                            )}
                                        </Form>
                                    </FormActions>
                                </>
                            ) : (
                                <>
                                    <p className="text-sm text-fg-2">
                                        Necesitas una app de autenticación en tu
                                        teléfono (Google Authenticator,
                                        1Password, Authy…). La configuras una
                                        vez escaneando un código QR.
                                    </p>

                                    <FormActions>
                                        {hasSetupData ? (
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    setShowSetupModal(true)
                                                }
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
                                                        size="sm"
                                                        type="submit"
                                                        disabled={processing}
                                                    >
                                                        <ShieldCheck />
                                                        Activar
                                                    </Button>
                                                )}
                                            </Form>
                                        )}
                                    </FormActions>
                                </>
                            )}
                        </FormCard>

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
                    </SettingsSection>
                )}
            </SettingsPage>
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'Seguridad',
            href: edit(),
        },
    ],
};
