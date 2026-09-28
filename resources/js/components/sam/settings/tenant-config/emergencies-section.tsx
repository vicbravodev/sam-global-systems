import { useState } from 'react';
import InputError from '@/components/input-error';
import { Field, FormCard } from '@/components/sam/field';
import {
    FormActions,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { putJson } from '@/lib/sam-fetch';
import { RecommendedConfigCard } from './recommended-config';
import {
    LIVE_LOCATION_DEFAULT_SECONDS,
    LIVE_LOCATION_KEY,
    MEDIA_AUTO_REQUEST_KEY,
    PANIC_AUTO_CLOSE_KEY,
    secondsToMinutesInput,
} from './settings-catalog';
import { submit, useTeamBase } from './shared';
import type { SettingRow } from './types';

export function EmergenciesSection({
    settings,
    canManage,
}: {
    settings: SettingRow[];
    canManage: boolean;
}) {
    const base = useTeamBase();
    const byKey = (key: string) => settings.find((s) => s.key === key);

    const [autoRequest, setAutoRequest] = useState(
        Boolean(byKey(MEDIA_AUTO_REQUEST_KEY)?.value ?? false),
    );
    const [panicMode, setPanicMode] = useState(
        String(byKey(PANIC_AUTO_CLOSE_KEY)?.value ?? 'annotate'),
    );
    // El backend guarda segundos; aquí se edita en minutos (sólo
    // presentación: al guardar se vuelve a convertir).
    const [stalenessMinutes, setStalenessMinutes] = useState(
        secondsToMinutesInput(
            Number(
                byKey(LIVE_LOCATION_KEY)?.value ??
                    LIVE_LOCATION_DEFAULT_SECONDS,
            ),
        ),
    );
    const [stalenessError, setStalenessError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const save = async () => {
        if (base === null || saving) {
            return;
        }

        // D-07: sin coerciones silenciosas; debe quedar en al menos 1 s.
        const minutes = Number(stalenessMinutes.replace(',', '.'));
        const seconds = Math.round(minutes * 60);

        if (
            stalenessMinutes.trim() === '' ||
            !Number.isFinite(minutes) ||
            seconds < 1
        ) {
            setStalenessError('Escribe un tiempo mayor que cero, en minutos.');

            return;
        }

        setStalenessError(null);
        setSaving(true);

        const result = await submit(
            putJson(`${base}/settings`, {
                settings: [
                    {
                        setting_key: MEDIA_AUTO_REQUEST_KEY,
                        setting_group: 'operational',
                        value_type: 'boolean',
                        value: autoRequest,
                    },
                    {
                        setting_key: PANIC_AUTO_CLOSE_KEY,
                        setting_group: 'operational',
                        value_type: 'string',
                        value: panicMode,
                    },
                    {
                        setting_key: LIVE_LOCATION_KEY,
                        setting_group: 'operational',
                        value_type: 'number',
                        value: seconds,
                    },
                ],
            }),
            'Ajustes de emergencias guardados.',
        );

        if (!result.ok) {
            // La ubicación viaja como settings[2] en el payload de arriba.
            setStalenessError(result.fieldErrors['settings.2.value'] ?? null);
        }

        setSaving(false);
    };

    return (
        <>
            {canManage ? <RecommendedConfigCard /> : null}

            <SettingsSection
                title="Cuando ocurre un evento crítico"
                description="Lo que SAM hace por su cuenta, sin esperar a un operador, ante un pánico o un evento grave."
            >
                <FormCard>
                    <Field
                        label="Pedir el video automáticamente"
                        help="SAM pide a la cámara el video del momento en cuanto llega el evento. Usa la cuota de descargas de video de tu proveedor."
                        htmlFor="tc-auto-request"
                    >
                        <div className="flex items-center gap-2.5">
                            <Switch
                                id="tc-auto-request"
                                checked={autoRequest}
                                disabled={!canManage}
                                onCheckedChange={setAutoRequest}
                            />
                            <span className="text-sm text-fg-2">
                                {autoRequest ? 'Activado' : 'Desactivado'}
                            </span>
                        </div>
                    </Field>

                    <Field
                        label="Pánico atendido fuera de SAM"
                        help="Si alguien marca el pánico como resuelto en la plataforma del proveedor. Un pánico cancelado puede ser coacción: por eso, por defecto, sólo se deja nota."
                        htmlFor="tc-panic-mode"
                    >
                        <Select
                            value={panicMode}
                            disabled={!canManage}
                            onValueChange={setPanicMode}
                        >
                            <SelectTrigger
                                id="tc-panic-mode"
                                className="h-9 w-full sm:w-72"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="annotate">
                                    Dejar nota y mantener abierto
                                </SelectItem>
                                <SelectItem value="close">
                                    Cerrar el incidente
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>

                    <Field
                        label="Ubicación desactualizada"
                        help="Si la última posición conocida de la unidad es más vieja que esto, SAM pide una nueva al proveedor antes de evaluar el evento."
                        htmlFor="tc-staleness"
                    >
                        <div className="flex items-center gap-2">
                            <Input
                                id="tc-staleness"
                                type="number"
                                inputMode="decimal"
                                min={0.1}
                                step={0.5}
                                value={stalenessMinutes}
                                disabled={!canManage}
                                aria-invalid={Boolean(stalenessError)}
                                onChange={(e) =>
                                    setStalenessMinutes(e.target.value)
                                }
                                className="w-28 tabular-nums"
                            />
                            <span className="text-sm text-fg-3">minutos</span>
                        </div>
                        <InputError message={stalenessError ?? undefined} />
                    </Field>

                    {canManage ? (
                        <FormActions hint="Los cambios aplican a los eventos nuevos.">
                            <Button
                                size="sm"
                                onClick={() => void save()}
                                disabled={saving}
                            >
                                Guardar cambios
                            </Button>
                        </FormActions>
                    ) : null}
                </FormCard>
            </SettingsSection>
        </>
    );
}
