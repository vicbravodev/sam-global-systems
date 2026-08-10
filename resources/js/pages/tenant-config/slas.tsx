import { Head, useForm, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { toast } from 'sonner';
import { update } from '@/actions/App/Http/Controllers/TenantConfig/IncidentSlaController';
import InputError from '@/components/input-error';
import { Field, FormCard } from '@/components/sam/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

interface PriorityRow {
    id: number;
    code: string;
    name: string;
    default_sla_seconds: number | null;
    sla_seconds: number | null;
}

interface SlasPageProps {
    priorities: PriorityRow[];
}

/** Redondea segundos a minutos enteros para la entrada del formulario. */
function toMinutes(seconds: number | null): number | null {
    return seconds === null ? null : Math.round(seconds / 60);
}

export default function TenantConfigSlas() {
    const page = usePage();
    const { priorities } = page.props as unknown as SlasPageProps;
    const currentTeam = (
        page.props as unknown as { currentTeam?: { slug?: string } | null }
    ).currentTeam;

    const form = useForm<{
        slas: { incident_priority_id: number; sla_seconds: number | null }[];
    }>({
        slas: priorities.map((priority) => ({
            incident_priority_id: priority.id,
            sla_seconds: priority.sla_seconds,
        })),
    });

    const defaults = useMemo(
        () =>
            new Map(
                priorities.map((priority) => [
                    priority.id,
                    priority.default_sla_seconds,
                ]),
            ),
        [priorities],
    );

    const setMinutes = (index: number, raw: string) => {
        const next = [...form.data.slas];
        const parsed = raw === '' ? null : Math.max(0, Number(raw)) * 60;
        next[index] = { ...next[index], sla_seconds: parsed };
        form.setData('slas', next);
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (!currentTeam?.slug) {
            return;
        }

        form.put(update.url({ current_team: currentTeam.slug }), {
            preserveScroll: true,
            onSuccess: () =>
                toast.success('Tiempos de respuesta actualizados.'),
        });
    };

    return (
        <>
            <Head title="Tiempos de respuesta" />
            <div className="flex flex-col gap-4 p-5">
                <PageHeader
                    title="Tiempos de respuesta"
                    description="Minutos que tiene el equipo para reconocer un incidente de cada prioridad antes de que SAM escale. Deja el campo vacío para usar el valor recomendado."
                />

                <form onSubmit={submit} className="flex flex-col gap-4">
                    <FormCard>
                        {priorities.map((priority, index) => {
                            const row = form.data.slas[index];
                            const defaultSeconds =
                                defaults.get(priority.id) ?? null;
                            const fieldError =
                                form.errors[
                                    `slas.${index}.sla_seconds` as keyof typeof form.errors
                                ];

                            return (
                                <Field
                                    key={priority.id}
                                    label={priority.name}
                                    help={
                                        defaultSeconds !== null
                                            ? `Recomendado: ${toMinutes(defaultSeconds)} min`
                                            : 'Sin valor recomendado'
                                    }
                                    htmlFor={`sla-${priority.id}`}
                                >
                                    <div className="flex items-center gap-2">
                                        <Input
                                            id={`sla-${priority.id}`}
                                            type="number"
                                            min={0}
                                            max={1440}
                                            step={1}
                                            className="w-28"
                                            placeholder={
                                                defaultSeconds !== null
                                                    ? String(
                                                          toMinutes(
                                                              defaultSeconds,
                                                          ),
                                                      )
                                                    : 'sin SLA'
                                            }
                                            value={
                                                row.sla_seconds === null
                                                    ? ''
                                                    : String(
                                                          toMinutes(
                                                              row.sla_seconds,
                                                          ),
                                                      )
                                            }
                                            onChange={(event) =>
                                                setMinutes(
                                                    index,
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <span className="text-xs text-fg-3">
                                            minutos
                                        </span>
                                    </div>
                                    <InputError message={fieldError} />
                                </Field>
                            );
                        })}
                    </FormCard>

                    <div>
                        <Button
                            type="submit"
                            disabled={form.processing}
                            data-test="update-slas-button"
                        >
                            Guardar
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

TenantConfigSlas.layout = (props: {
    currentTeam?: { slug: string } | null;
}) => ({
    breadcrumbs: [
        {
            title: 'Configuración',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/settings/tenant-config`
                : '#',
        },
        {
            title: 'Tiempos de respuesta',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/settings/tenant-config/slas`
                : '#',
        },
    ],
});
