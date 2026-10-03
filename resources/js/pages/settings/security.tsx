import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Field, FormCard } from '@/components/sam/field';
import {
    FormActions,
    SettingsPage,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { TwoFactorSection } from '@/components/sam/settings/two-factor-section';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/security';

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
                    <TwoFactorSection
                        requiresConfirmation={requiresConfirmation}
                        twoFactorEnabled={twoFactorEnabled}
                    />
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
