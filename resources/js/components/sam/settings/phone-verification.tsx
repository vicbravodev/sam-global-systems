import { Form } from '@inertiajs/react';
import PhoneVerificationController from '@/actions/App/Http/Controllers/Settings/PhoneVerificationController';
import InputError from '@/components/input-error';
import { Field, FormCard } from '@/components/sam/field';
import {
    FormActions,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
                description={`${phone} recibe las llamadas, SMS y WhatsApp de emergencia.`}
            >
                {null}
            </SettingsSection>
        );
    }

    const codeSent = status === 'phone-otp-sent';

    return (
        <SettingsSection
            title="Verifica tu teléfono"
            description={`Te enviamos un código por SMS a ${phone}.`}
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
                                    htmlFor="code"
                                >
                                    <Input
                                        id="code"
                                        name="code"
                                        inputMode="numeric"
                                        autoComplete="one-time-code"
                                        maxLength={6}
                                        required
                                        className="w-40"
                                        placeholder="123456"
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
