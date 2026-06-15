import { Form, Head, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Confirmar contraseña" />

            <div className="mb-6 flex items-start gap-3 rounded-md border border-border bg-surface-2 p-3 text-sm text-fg-2">
                <ShieldCheck size={18} className="mt-0.5 shrink-0 text-fg-3" />
                <p className="m-0">
                    Sigues con la sesión iniciada
                    {auth?.user?.email ? (
                        <>
                            {' '}
                            como{' '}
                            <span className="font-medium text-foreground">
                                {auth.user.email}
                            </span>
                        </>
                    ) : null}
                    . Por seguridad, confirma tu contraseña antes de continuar
                    con esta acción sensible. No se cerrará tu sesión.
                </p>
            </div>

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                resetOnError={['password']}
            >
                {({ processing, errors }) => (
                    <div className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="password">Contraseña</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                placeholder="Contraseña"
                                autoComplete="current-password"
                                autoFocus
                            />

                            <InputError message={errors.password} />
                        </div>

                        <div className="flex items-center">
                            <Button
                                className="w-full"
                                disabled={processing}
                                data-test="confirm-password-button"
                            >
                                {processing && <Spinner />}
                                Confirmar contraseña
                            </Button>
                        </div>
                    </div>
                )}
            </Form>
        </>
    );
}

ConfirmPassword.layout = {
    title: 'Confirma tu contraseña',
    description:
        'Esta es un área segura de la aplicación. Confirma tu contraseña antes de continuar.',
};
