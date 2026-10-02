import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/onboarding';

type Props = {
    token: string;
    email: string;
    team: string | null;
};

/**
 * Primer acceso de un usuario dado de alta desde la consola SAM: define su
 * contraseña con el enlace del correo de bienvenida y entra a su empresa.
 */
export default function SetPassword({ token, email, team }: Props) {
    return (
        <>
            <Head title="Activa tu cuenta" />

            <Form
                {...store.form()}
                transform={(data) => ({ ...data, token, email, team })}
                resetOnError={['password', 'password_confirmation']}
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Correo electrónico</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="username"
                                value={email}
                                className="mt-1 block w-full"
                                readOnly
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">Contraseña</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                autoFocus
                                placeholder="Mínimo 12 caracteres"
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
                                autoComplete="new-password"
                                className="mt-1 block w-full"
                                placeholder="Repite la contraseña"
                            />
                            <InputError
                                message={errors.password_confirmation}
                            />
                        </div>

                        <Button
                            type="submit"
                            className="mt-2 w-full"
                            disabled={processing}
                            data-test="set-password-button"
                        >
                            {processing && <Spinner />}
                            Activar mi cuenta
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

SetPassword.layout = {
    title: 'Activa tu cuenta',
    description:
        'Define tu contraseña para entrar a SAM. Tu correo quedará verificado.',
};
