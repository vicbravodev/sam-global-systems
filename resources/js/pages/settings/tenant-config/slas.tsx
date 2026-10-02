import { Head, useForm, usePage } from '@inertiajs/react';
import { Timer } from 'lucide-react';
import { useMemo } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { update } from '@/actions/App/Http/Controllers/TenantConfig/IncidentSlaController';
import { Field, FormCard } from '@/components/sam/field';
import {
    FormActions,
    SettingsPage,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { priorityLabel } from '@/lib/labels';

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

    const setSeconds = (index: number, seconds: number | null) => {
        const next = [...form.data.slas];
        next[index] = { ...next[index], sla_seconds: seconds };
        form.setData('slas', next);
    };

    const setMinutes = (index: number, raw: string) =>
        setSeconds(index, raw === '' ? null : Math.max(0, Number(raw)) * 60);

    const submit = (event: FormEvent) => {
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
            <SettingsPage
                title="Tiempos de respuesta"
                description="Cuánto tiempo tiene tu equipo para atender un incidente, según su gravedad, antes de que SAM lo escale."
            >
                <SettingsSection
                    title="Tiempo para atender cada incidente"
                    description="Déjalo vacío para usar el tiempo que recomienda SAM."
                >
                    {priorities.length === 0 ? (
                        <FormCard>
                            <EmptyState
                                className="py-6"
                                icon={Timer}
                                title="Aún no hay niveles de gravedad"
                                description="SAM todavía no cargó los niveles de gravedad de los incidentes. Escribe a soporte."
                            />
                        </FormCard>
                    ) : (
                        <form onSubmit={submit}>
                            <FormCard>
                                {priorities.map((priority, index) => {
                                    const row = form.data.slas[index];
                                    const defaultSeconds =
                                        defaults.get(priority.id) ?? null;
                                    const customized = row.sla_seconds !== null;
                                    const fieldError =
                                        form.errors[
                                            `slas.${index}.sla_seconds` as keyof typeof form.errors
                                        ];

                                    return (
                                        <Field
                                            error={fieldError}
                                            key={priority.id}
                                            label={priorityLabel(priority.code)}
                                            help={
                                                defaultSeconds !== null
                                                    ? `Recomendado por SAM: ${toMinutes(defaultSeconds)} min`
                                                    : 'SAM no recomienda un tiempo para esta gravedad: vacío, no se escala por tiempo.'
                                            }
                                            htmlFor={`sla-${priority.id}`}
                                        >
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Input
                                                    id={`sla-${priority.id}`}
                                                    type="number"
                                                    min={1}
                                                    max={1440}
                                                    step={1}
                                                    className="w-28 tabular-nums"
                                                    placeholder={
                                                        defaultSeconds !== null
                                                            ? String(
                                                                  toMinutes(
                                                                      defaultSeconds,
                                                                  ),
                                                              )
                                                            : 'Sin límite'
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
                                                <span className="text-sm text-fg-3">
                                                    minutos
                                                </span>
                                                {customized ? (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-fg-3"
                                                        onClick={() =>
                                                            setSeconds(
                                                                index,
                                                                null,
                                                            )
                                                        }
                                                    >
                                                        {defaultSeconds !== null
                                                            ? 'Usar recomendado'
                                                            : 'Quitar límite'}
                                                    </Button>
                                                ) : (
                                                    <span className="text-2xs text-fg-3">
                                                        {defaultSeconds !== null
                                                            ? 'Usando el recomendado'
                                                            : 'Sin límite'}
                                                    </span>
                                                )}
                                            </div>
                                        </Field>
                                    );
                                })}
                                <FormActions
                                    hint={
                                        form.isDirty
                                            ? 'Tienes cambios sin guardar.'
                                            : undefined
                                    }
                                >
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={form.processing}
                                        data-test="update-slas-button"
                                    >
                                        Guardar cambios
                                    </Button>
                                </FormActions>
                            </FormCard>
                        </form>
                    )}
                </SettingsSection>
            </SettingsPage>
        </>
    );
}

TenantConfigSlas.layout = (props: {
    currentTeam?: { slug: string } | null;
}) => ({
    breadcrumbs: [
        {
            title: 'Configuración de la empresa',
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
