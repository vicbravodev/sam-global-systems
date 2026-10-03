import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Field, FormCard } from '@/components/sam/field';
import {
    FormActions,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { update } from '@/routes/teams';
import type { Team } from '@/types';

/** "Datos del equipo": the team name form. */
export function TeamDetailsSection({ team }: { team: Team }) {
    return (
        <SettingsSection
            title="Datos del equipo"
            description="El nombre se muestra en el selector de equipos y en los correos de invitación."
        >
            <Form {...update.form(team.slug)}>
                {({ errors, processing }) => (
                    <FormCard>
                        <Field label="Nombre del equipo" htmlFor="name">
                            <Input
                                id="name"
                                name="name"
                                data-test="team-name-input"
                                defaultValue={team.name}
                                required
                            />
                            <InputError message={errors.name} />
                        </Field>
                        <FormActions>
                            <Button
                                type="submit"
                                size="sm"
                                data-test="team-save-button"
                                disabled={processing}
                            >
                                Guardar cambios
                            </Button>
                        </FormActions>
                    </FormCard>
                )}
            </Form>
        </SettingsSection>
    );
}
