import { Form } from '@inertiajs/react';
import { ShieldCheck, ShieldOff } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { FormCard } from '@/components/sam/field';
import { StatePill } from '@/components/sam/settings/controls';
import {
    FormActions,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Button } from '@/components/ui/button';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { disable, enable } from '@/routes/two-factor';

export interface TwoFactorSectionProps {
    requiresConfirmation: boolean;
    twoFactorEnabled: boolean;
}

export function TwoFactorSection({
    requiresConfirmation,
    twoFactorEnabled,
}: TwoFactorSectionProps) {
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
                            Al iniciar sesión te pediremos el código de tu app
                            de autenticación (Google Authenticator, 1Password,
                            Authy…).
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
                            Necesitas una app de autenticación en tu teléfono
                            (Google Authenticator, 1Password, Authy…). La
                            configuras una vez escaneando un código QR.
                        </p>

                        <FormActions>
                            {hasSetupData ? (
                                <Button
                                    size="sm"
                                    onClick={() => setShowSetupModal(true)}
                                >
                                    <ShieldCheck />
                                    Continuar configuración
                                </Button>
                            ) : (
                                <Form
                                    {...enable.form()}
                                    onSuccess={() => setShowSetupModal(true)}
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
    );
}
