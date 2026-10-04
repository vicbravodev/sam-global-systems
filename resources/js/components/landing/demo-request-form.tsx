import { useForm } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { useState } from 'react';
import { FormField } from '@/components/sam/form-field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { store } from '@/routes/demo-request';

export interface DemoRequestFormProps {
    fleetSizes: string[];
}

export function DemoRequestForm({ fleetSizes }: DemoRequestFormProps) {
    const [sent, setSent] = useState(false);
    const form = useForm({
        name: '',
        company: '',
        email: '',
        phone: '',
        fleet_size: '',
        message: '',
        // Campo trampa: invisible para personas; si llega lleno es un bot.
        website: '',
    });

    type Field = keyof typeof form.data;

    // Al corregir un campo se quita su error: el aviso rojo no se queda
    // pegado hasta el siguiente envío.
    const set = (field: Field, value: string) => {
        form.setData(field, value);
        form.clearErrors(field);
    };

    const submit = () => {
        form.post(store().url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setSent(true);
            },
        });
    };

    if (sent) {
        return (
            <div
                role="status"
                className="flex flex-col items-start gap-4 rounded-2xl border border-brand-line bg-white p-8 shadow-sm"
            >
                <CheckCircle2 className="size-10 text-brand-aqua" />
                <h2 className="text-2xl font-semibold tracking-tight">
                    ¡Listo! Recibimos tu solicitud.
                </h2>
                <p className="text-base leading-relaxed text-brand-ink-2">
                    Te escribimos en menos de un día hábil para agendar la
                    llamada. Si es urgente, márcanos al teléfono de abajo.
                </p>
            </div>
        );
    }

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                submit();
            }}
            noValidate
            className="grid gap-5 rounded-2xl border border-brand-line bg-white p-6 shadow-sm sm:p-8"
        >
            <div className="grid gap-5 sm:grid-cols-2">
                <FormField
                    label="Nombre"
                    htmlFor="demo-name"
                    error={form.errors.name}
                >
                    <Input
                        id="demo-name"
                        autoComplete="name"
                        value={form.data.name}
                        onChange={(e) => set('name', e.target.value)}
                        required
                    />
                </FormField>
                <FormField
                    label="Empresa"
                    htmlFor="demo-company"
                    error={form.errors.company}
                >
                    <Input
                        id="demo-company"
                        autoComplete="organization"
                        value={form.data.company}
                        onChange={(e) => set('company', e.target.value)}
                        required
                    />
                </FormField>
                <FormField
                    label="Correo"
                    htmlFor="demo-email"
                    error={form.errors.email}
                >
                    <Input
                        id="demo-email"
                        type="email"
                        autoComplete="email"
                        value={form.data.email}
                        onChange={(e) => set('email', e.target.value)}
                        required
                    />
                </FormField>
                <FormField
                    label="Teléfono (opcional)"
                    htmlFor="demo-phone"
                    error={form.errors.phone}
                >
                    <Input
                        id="demo-phone"
                        type="tel"
                        autoComplete="tel"
                        value={form.data.phone}
                        onChange={(e) => set('phone', e.target.value)}
                    />
                </FormField>
            </div>

            <fieldset className="grid gap-2">
                <legend className="mb-2 text-sm font-medium">
                    ¿Cuántas unidades tiene tu flota?
                </legend>
                <div className="flex flex-wrap gap-2">
                    {fleetSizes.map((size) => {
                        const checked = form.data.fleet_size === size;

                        return (
                            <label
                                key={size}
                                className={cn(
                                    'cursor-pointer rounded-full border px-4 py-2 text-base transition-colors has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-brand-teal',
                                    checked
                                        ? 'border-brand-teal bg-brand-mist font-medium text-brand-petrol'
                                        : 'border-brand-line text-brand-ink-2 hover:border-brand-teal/60',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="fleet_size"
                                    value={size}
                                    checked={checked}
                                    onChange={() => set('fleet_size', size)}
                                    className="sr-only"
                                />
                                {size}
                            </label>
                        );
                    })}
                </div>
                {form.errors.fleet_size ? (
                    <p className="text-sm text-destructive">
                        {form.errors.fleet_size}
                    </p>
                ) : null}
            </fieldset>

            <FormField
                label="¿Qué te gustaría ver? (opcional)"
                htmlFor="demo-message"
                error={form.errors.message}
            >
                <Textarea
                    id="demo-message"
                    rows={4}
                    value={form.data.message}
                    onChange={(e) => set('message', e.target.value)}
                    placeholder="Por ejemplo: botones de pánico, robos de noche, cámaras…"
                />
            </FormField>

            <div aria-hidden="true" className="sr-only">
                <label htmlFor="demo-website">No llenar</label>
                <input
                    id="demo-website"
                    type="text"
                    tabIndex={-1}
                    autoComplete="off"
                    value={form.data.website}
                    onChange={(e) => set('website', e.target.value)}
                />
            </div>

            <p className="sr-only" aria-live="polite">
                {form.hasErrors ? 'Revisa los campos marcados.' : ''}
            </p>

            <button
                type="submit"
                disabled={form.processing}
                className="inline-flex h-12 items-center justify-center gap-2 rounded-full bg-brand-teal px-7 text-md font-medium text-white shadow-[0_8px_20px_-8px_rgba(0,128,159,0.6)] transition-[background-color,transform] duration-200 hover:bg-brand-petrol focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-teal active:scale-[0.97] disabled:opacity-60 sm:justify-self-start"
            >
                {form.processing ? <Spinner /> : null}
                Pedir mi demo
            </button>
        </form>
    );
}
