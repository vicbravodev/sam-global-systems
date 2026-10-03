import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { DEFAULT_TENANT_TIMEZONE, TENANT_TIMEZONES } from '@/lib/timezones';
import { store as adminTenantStore } from '@/routes/admin/tenants';
import { NO_PLAN } from './lib';
import type { PlanOption } from './types';

export function CreateTenantSheet({
    open,
    onOpenChange,
    plans,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    plans: PlanOption[];
}) {
    const form = useForm({
        name: '',
        timezone: DEFAULT_TENANT_TIMEZONE,
        plan_code: NO_PLAN,
        owner_email: '',
        owner_name: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            plan_code: data.plan_code === NO_PLAN ? null : data.plan_code,
        }));
        form.post(adminTenantStore().url, {
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="flex w-full flex-col gap-0 p-0 sm:max-w-md">
                <form
                    onSubmit={submit}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <SheetHeader className="border-b border-border px-5 py-4">
                        <SheetTitle>Nuevo cliente</SheetTitle>
                        <SheetDescription>
                            Da de alta la empresa y a su responsable. Nace con
                            el paquete por defecto (reglas, escalación y
                            ajustes) listo.
                        </SheetDescription>
                    </SheetHeader>

                    <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto px-5 py-5">
                        <fieldset className="grid gap-4">
                            <legend className="sam-caps mb-1 text-fg-3">
                                Empresa
                            </legend>
                            <div className="grid gap-1.5">
                                <Label htmlFor="tenant-name">Nombre</Label>
                                <Input
                                    id="tenant-name"
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                    placeholder="Transportes del Norte"
                                    autoFocus
                                    required
                                />
                                <InputError message={form.errors.name} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="tenant-timezone">
                                    Zona horaria
                                </Label>
                                <Select
                                    value={form.data.timezone}
                                    onValueChange={(v) =>
                                        form.setData('timezone', v)
                                    }
                                >
                                    <SelectTrigger
                                        id="tenant-timezone"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {TENANT_TIMEZONES.map((tz) => (
                                            <SelectItem
                                                key={tz.value}
                                                value={tz.value}
                                            >
                                                {tz.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="text-xs text-fg-3">
                                    Rige el horario silencioso, los reportes y
                                    el contexto de la IA.
                                </p>
                                <InputError message={form.errors.timezone} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="tenant-plan">
                                    Topes de plan
                                </Label>
                                <Select
                                    value={form.data.plan_code}
                                    onValueChange={(v) =>
                                        form.setData('plan_code', v)
                                    }
                                >
                                    <SelectTrigger
                                        id="tenant-plan"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NO_PLAN}>
                                            Sin plan (sólo términos propios)
                                        </SelectItem>
                                        {plans.map((plan) => (
                                            <SelectItem
                                                key={plan.code}
                                                value={plan.code}
                                            >
                                                {plan.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.plan_code} />
                            </div>
                        </fieldset>

                        <fieldset className="grid gap-4">
                            <legend className="sam-caps mb-1 text-fg-3">
                                Responsable
                            </legend>
                            <div className="grid gap-1.5">
                                <Label htmlFor="owner-email">Correo</Label>
                                <Input
                                    id="owner-email"
                                    type="email"
                                    value={form.data.owner_email}
                                    onChange={(e) =>
                                        form.setData(
                                            'owner_email',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="direccion@empresa.mx"
                                    autoComplete="off"
                                    required
                                />
                                <InputError message={form.errors.owner_email} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="owner-name">Nombre</Label>
                                <Input
                                    id="owner-name"
                                    value={form.data.owner_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'owner_name',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Obligatorio si aún no tiene cuenta"
                                />
                                <InputError message={form.errors.owner_name} />
                            </div>
                        </fieldset>

                        <div className="rounded-lg border border-border bg-surface-2 px-4 py-3">
                            <p className="sam-caps mb-2 text-fg-3">
                                Qué pasa después
                            </p>
                            <ol className="grid list-decimal gap-1.5 pl-4 text-sm text-fg-2">
                                <li>
                                    El responsable recibe un correo para definir
                                    su contraseña (válido 7 días).
                                </li>
                                <li>
                                    Conecta su proveedor (Samsara) en
                                    Integraciones.
                                </li>
                                <li>
                                    Elige qué unidades vigilar; desde ahí se
                                    cobra por tracto-día.
                                </li>
                            </ol>
                        </div>
                    </div>

                    <SheetFooter className="flex-row justify-end gap-2 border-t border-border px-5 py-3">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                            disabled={form.processing}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Crear cliente
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
