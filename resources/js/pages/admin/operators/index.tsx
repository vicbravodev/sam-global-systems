import { Head, router, useForm } from '@inertiajs/react';
import { ShieldCheck, ShieldOff, UsersRound } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { BillingPill } from '@/components/sam/billing/panel';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import { EntityAvatar } from '@/components/sam/entity-avatar';
import { ListPage } from '@/components/sam/list-page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/format';
import {
    destroy as demoteRoute,
    index as operatorsIndex,
    store as promoteRoute,
} from '@/routes/admin/operators';

interface Operator {
    id: number;
    name: string;
    email: string;
    isYou: boolean;
    twoFactor: boolean;
    createdAt: string | null;
}

interface AdminOperatorsIndexProps {
    operators: Operator[];
}

export default function AdminOperatorsIndex({
    operators,
}: AdminOperatorsIndexProps) {
    const form = useForm({ email: '' });
    const [demoting, setDemoting] = useState<Operator | null>(null);
    const withoutTwoFactor = operators.filter((o) => !o.twoFactor).length;

    const promote = (e: FormEvent) => {
        e.preventDefault();
        form.post(promoteRoute().url, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const demote = (operator: Operator) =>
        new Promise<void>((resolve) => {
            router.delete(demoteRoute(operator.id).url, {
                preserveScroll: true,
                onError: (errors) =>
                    toast.error(
                        Object.values(errors)[0] ?? 'No se pudo quitar el rol.',
                    ),
                onFinish: () => resolve(),
            });
        });

    return (
        <>
            <Head title="Operadores" />
            <ListPage
                title="Operadores"
                description="Personas de SAM con acceso a todos los clientes."
                meta={
                    <span className="text-xs text-fg-3">
                        <span className="font-medium text-fg-1">
                            {operators.length}
                        </span>{' '}
                        {operators.length === 1 ? 'operador' : 'operadores'}
                    </span>
                }
            >
                <div className="min-h-0 flex-1 overflow-y-auto p-5">
                    <div className="grid max-w-4xl gap-4">
                        {withoutTwoFactor > 0 ? (
                            <div
                                role="status"
                                className="flex items-start gap-3 rounded-lg border border-severity-medium/40 bg-severity-medium/10 px-4 py-3 text-sm"
                            >
                                <ShieldOff className="mt-0.5 size-4 shrink-0 text-severity-medium" />
                                <p className="text-fg-2">
                                    {withoutTwoFactor === 1
                                        ? 'Un operador no tiene'
                                        : `${withoutTwoFactor} operadores no tienen`}{' '}
                                    verificación en dos pasos. Un operador ve a
                                    todos los clientes: actívala en
                                    Configuración → Seguridad.
                                </p>
                            </div>
                        ) : null}

                        <section className="rounded-lg border border-border bg-surface-1">
                            {operators.length === 0 ? (
                                <EmptyState
                                    icon={UsersRound}
                                    title="Sin operadores"
                                    description="Crea el primero con php artisan sam:create-super-admin."
                                />
                            ) : (
                                <ul className="divide-y divide-border">
                                    {operators.map((operator) => (
                                        <li
                                            key={operator.id}
                                            className="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3"
                                        >
                                            <EntityAvatar
                                                name={operator.name}
                                            />
                                            <div className="min-w-0 flex-1">
                                                <p className="flex flex-wrap items-center gap-1.5 text-sm font-medium">
                                                    <span className="truncate">
                                                        {operator.name}
                                                    </span>
                                                    {operator.isYou ? (
                                                        <BillingPill tone="info">
                                                            Tú
                                                        </BillingPill>
                                                    ) : null}
                                                </p>
                                                <p className="truncate text-xs text-fg-3">
                                                    {operator.email}
                                                    {operator.createdAt
                                                        ? ` · desde ${formatDate(operator.createdAt)}`
                                                        : ''}
                                                </p>
                                            </div>
                                            {operator.twoFactor ? (
                                                <BillingPill tone="ok">
                                                    <ShieldCheck className="size-3" />
                                                    2FA activa
                                                </BillingPill>
                                            ) : (
                                                <BillingPill tone="warn">
                                                    Sin 2FA
                                                </BillingPill>
                                            )}
                                            {operator.isYou ? null : (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="text-destructive"
                                                    onClick={() =>
                                                        setDemoting(operator)
                                                    }
                                                >
                                                    Quitar rol
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <section className="rounded-lg border border-border bg-surface-1 p-4">
                            <h2 className="sam-h3">Añadir operador</h2>
                            <p className="mt-0.5 text-xs text-fg-3">
                                Da el rol a una cuenta que ya existe. Tendrá
                                acceso a todos los clientes y a esta consola.
                            </p>
                            <form
                                onSubmit={promote}
                                className="mt-3 flex flex-wrap items-start gap-2"
                            >
                                <div className="grid min-w-60 flex-1 gap-1.5">
                                    <Label
                                        htmlFor="operator-email"
                                        className="sr-only"
                                    >
                                        Correo de la cuenta
                                    </Label>
                                    <Input
                                        id="operator-email"
                                        type="email"
                                        value={form.data.email}
                                        onChange={(e) =>
                                            form.setData(
                                                'email',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="persona@samglobal.mx"
                                        required
                                    />
                                    <InputError message={form.errors.email} />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={
                                        form.processing ||
                                        form.data.email === ''
                                    }
                                >
                                    {form.processing && <Spinner />}
                                    Dar rol de operador
                                </Button>
                            </form>
                        </section>
                    </div>
                </div>
            </ListPage>

            <ConfirmDialog
                open={demoting !== null}
                title={`Quitar el rol de operador a ${demoting?.name ?? ''}`}
                description={`${demoting?.email ?? ''} pierde el acceso a esta consola y a todos los clientes. Su cuenta sigue existiendo.`}
                confirmLabel="Quitar rol"
                onOpenChange={(open) => !open && setDemoting(null)}
                onConfirm={async () => {
                    if (demoting) {
                        await demote(demoting);
                    }

                    setDemoting(null);
                }}
            />
        </>
    );
}

AdminOperatorsIndex.layout = {
    breadcrumbs: [{ title: 'Operadores', href: operatorsIndex().url }],
};
