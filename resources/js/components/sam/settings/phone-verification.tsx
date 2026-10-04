import { Form } from '@inertiajs/react';
import PhoneVerificationController from '@/actions/App/Http/Controllers/Settings/PhoneVerificationController';
import InputError from '@/components/input-error';
import { Field, FormCard } from '@/components/sam/field';
import {
    FormActions,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { VerificationCodeInput } from '@/components/sam/verification-code-input';
import { Button } from '@/components/ui/button';
import { formatPhone } from '@/lib/phone';
import { TONE_TEXT } from '@/lib/tone';
import { cn } from '@/lib/utils';

export interface PhoneVerificationProps {
    phone: string;
    verified: boolean;
    status?: string;
}

export function PhoneVerification({
    phone,
    verified,
    status,
}: PhoneVerificationProps) {
    if (verified) {
        return (
            <SettingsSection
                title="Teléfono verificado"
                description={`${formatPhone(phone)} recibe las llamadas, SMS y WhatsApp de emergencia.`}
            >
                {null}
            </SettingsSection>
        );
    }

    const codeSent = status === 'phone-otp-sent';

    return (
        <SettingsSection
            title="Verifica tu teléfono"
            description={`Te enviamos un código por SMS a ${formatPhone(phone)}.`}
        >
            <FormCard>
                <Form
                    {...PhoneVerificationController.send.form()}
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <div className="grid gap-2">
                            <div>
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}
                                    data-test="send-phone-code-button"
                                >
                                    {codeSent
                                        ? 'Reenviar código'
                                        : 'Enviar código'}
                                </Button>
                            </div>
                            <InputError message={errors.phone} />
                            {codeSent && (
                                <p
                                    className={cn(
                                        'text-xs font-medium',
                                        TONE_TEXT.ok,
                                    )}
                                >
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
                    >
                        {({ processing, errors }) => (
                            <>
                                <Field
                                    label="Código de 6 dígitos"
                                    help="Llega por SMS. Al escribir el último dígito se verifica solo."
                                    htmlFor="code"
                                >
                                    <VerificationCodeInput
                                        id="code"
                                        name="code"
                                        submitOnComplete
                                        autoFocus
                                        disabled={processing}
                                        aria-invalid={errors.code !== undefined}
                                    />
                                    <InputError message={errors.code} />
                                </Field>
                                <FormActions>
                                    <Button
                                        size="sm"
                                        disabled={processing}
                                        data-test="verify-phone-button"
                                    >
                                        Verificar
                                    </Button>
                                </FormActions>
                            </>
                        )}
                    </Form>
                )}
            </FormCard>
        </SettingsSection>
    );
}
