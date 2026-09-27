import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login, logout } from '@/routes';
import { accept, register } from '@/routes/invitations';

type Mode = 'register' | 'login' | 'accept' | 'wrong_account' | 'invalid';

type Props = {
    mode: Mode;
    code: string;
    email: string;
    teamName: string | null;
    roleLabel: string;
    inviterName: string | null;
    problem: string | null;
};

function Summary({
    teamName,
    roleLabel,
    inviterName,
}: Pick<Props, 'teamName' | 'roleLabel' | 'inviterName'>) {
    return (
        <p className="text-center text-sm text-muted-foreground">
            {inviterName ? `${inviterName} te invitó a ` : 'Te invitaron a '}
            <strong className="text-foreground">{teamName}</strong> como{' '}
            <strong className="text-foreground">{roleLabel}</strong>.
        </p>
    );
}

export default function AcceptInvitation({
    mode,
    code,
    email,
    teamName,
    roleLabel,
    inviterName,
    problem,
}: Props) {
    return (
        <>
            <Head title="Invitación" />

            {mode === 'invalid' ? (
                <div className="flex flex-col gap-6 text-center">
                    <p
                        className="text-sm text-destructive"
                        data-test="invitation-problem"
                    >
                        {problem}
                    </p>
                    <TextLink href={login()} className="mx-auto text-sm">
                        Ir a iniciar sesión
                    </TextLink>
                </div>
            ) : (
                <div className="flex flex-col gap-6">
                    <Summary
                        teamName={teamName}
                        roleLabel={roleLabel}
                        inviterName={inviterName}
                    />

                    {mode === 'register' && (
                        <Form
                            {...register.form(code)}
                            resetOnSuccess={[
                                'password',
                                'password_confirmation',
                            ]}
                            resetOnError={['password', 'password_confirmation']}
                            disableWhileProcessing
                            className="flex flex-col gap-6"
                        >
                            {({ processing, errors }) => (
                                <div className="grid gap-6">
                                    <InputError message={errors.invitation} />

                                    <div className="grid gap-2">
                                        <Label htmlFor="email">
                                            Correo electrónico
                                        </Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            value={email}
                                            readOnly
                                            autoComplete="email"
                                        />
                                        <InputError message={errors.email} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="name">Nombre</Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            type="text"
                                            required
                                            autoFocus
                                            autoComplete="name"
                                            placeholder="Nombre completo"
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="password">
                                            Contraseña
                                        </Label>
                                        <PasswordInput
                                            id="password"
                                            name="password"
                                            required
                                            autoComplete="new-password"
                                            placeholder="Contraseña"
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
                                            required
                                            autoComplete="new-password"
                                            placeholder="Confirmar contraseña"
                                        />
                                        <InputError
                                            message={
                                                errors.password_confirmation
                                            }
                                        />
                                    </div>

                                    <Button
                                        type="submit"
                                        className="w-full"
                                        disabled={processing}
                                        data-test="invitation-register-button"
                                    >
                                        {processing && <Spinner />}
                                        Crear cuenta y unirme
                                    </Button>
                                </div>
                            )}
                        </Form>
                    )}

                    {mode === 'login' && (
                        <div className="flex flex-col gap-4 text-center text-sm text-muted-foreground">
                            <p>
                                Ya existe una cuenta para{' '}
                                <strong className="text-foreground">
                                    {email}
                                </strong>
                                . Inicia sesión y volverás aquí para aceptar la
                                invitación.
                            </p>
                            <Button asChild className="w-full">
                                <Link href={login()}>Iniciar sesión</Link>
                            </Button>
                        </div>
                    )}

                    {mode === 'accept' && (
                        <Form
                            {...accept.form(code)}
                            disableWhileProcessing
                            className="flex flex-col gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <InputError message={errors.invitation} />
                                    <Button
                                        type="submit"
                                        className="w-full"
                                        disabled={processing}
                                        data-test="invitation-accept-button"
                                    >
                                        {processing && <Spinner />}
                                        Aceptar invitación
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}

                    {mode === 'wrong_account' && (
                        <div className="flex flex-col gap-4 text-center text-sm text-muted-foreground">
                            <p>
                                Esta invitación es para{' '}
                                <strong className="text-foreground">
                                    {email}
                                </strong>
                                , pero iniciaste sesión con otra cuenta. Cierra
                                sesión y vuelve a abrir el enlace.
                            </p>
                            <TextLink
                                href={logout()}
                                className="mx-auto block text-sm"
                            >
                                Cerrar sesión
                            </TextLink>
                        </div>
                    )}
                </div>
            )}
        </>
    );
}

AcceptInvitation.layout = {
    title: 'Únete a tu equipo en SAM',
    description: 'Acepta la invitación para empezar a operar tu flota.',
};
