import { usePage } from '@inertiajs/react';
import { CalendarClock, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import { Field, FormCard } from '@/components/sam/field';
import { ChipToggle } from '@/components/sam/settings/controls';
import { SettingsSection } from '@/components/sam/settings/settings-page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { humanizeCode } from '@/lib/labels';
import { putJson } from '@/lib/sam-fetch';
import { submit } from '@/lib/submit';
import tenantConfigRoutes from '@/routes/tenant-config';
import { CONFIG_SUBMIT, JsonField, parseJson } from './shared';
import type { RecipientOptions, ScheduleProfileRow } from './types';

const WEEKDAYS = [
    { value: 'monday', short: 'L', label: 'Lunes' },
    { value: 'tuesday', short: 'M', label: 'Martes' },
    { value: 'wednesday', short: 'X', label: 'Miércoles' },
    { value: 'thursday', short: 'J', label: 'Jueves' },
    { value: 'friday', short: 'V', label: 'Viernes' },
    { value: 'saturday', short: 'S', label: 'Sábado' },
    { value: 'sunday', short: 'D', label: 'Domingo' },
] as const;

/** Zonas horarias frecuentes, con el nombre que reconoce el operador. */
const TIMEZONES = [
    { value: 'America/Mexico_City', label: 'Centro de México (CDMX)' },
    { value: 'America/Monterrey', label: 'Monterrey' },
    { value: 'America/Cancun', label: 'Cancún (sureste)' },
    { value: 'America/Chihuahua', label: 'Chihuahua' },
    { value: 'America/Mazatlan', label: 'Pacífico mexicano (Mazatlán)' },
    { value: 'America/Hermosillo', label: 'Sonora (Hermosillo)' },
    { value: 'America/Tijuana', label: 'Noroeste (Tijuana)' },
    { value: 'America/Bogota', label: 'Colombia (Bogotá)' },
    { value: 'America/Lima', label: 'Perú (Lima)' },
    { value: 'America/Santiago', label: 'Chile (Santiago)' },
    {
        value: 'America/Argentina/Buenos_Aires',
        label: 'Argentina (Buenos Aires)',
    },
    { value: 'America/New_York', label: 'EE. UU. Este (Nueva York)' },
    { value: 'America/Chicago', label: 'EE. UU. Centro (Chicago)' },
    { value: 'America/Denver', label: 'EE. UU. Montaña (Denver)' },
    { value: 'America/Los_Angeles', label: 'EE. UU. Pacífico (Los Ángeles)' },
    { value: 'UTC', label: 'Hora universal (UTC)' },
];

/** Guardia editable en el formulario (formato de ResolveOnCallOperator). */
interface OnCallShiftDraft {
    id: number;
    userId: string;
    days: string[];
    start: string;
    end: string;
}

interface OnCallDraft {
    shifts: OnCallShiftDraft[];
    fallbackUserId: string;
}

let onCallDraftId = 0;

const ON_CALL_SHIFT_KEYS = new Set(['user_id', 'days', 'start', 'end']);
const ON_CALL_ROOT_KEYS = new Set(['on_call', 'fallback_on_call_user_id']);

/**
 * Convierte `shift_rules_json` al formulario. Devuelve null si la estructura
 * no es la que usa la asignación de guardia ({on_call: [...], fallback...}):
 * en ese caso se edita como texto para no perder datos.
 */
function parseOnCall(rules: unknown): OnCallDraft | null {
    if (
        rules === null ||
        rules === undefined ||
        (Array.isArray(rules) && rules.length === 0)
    ) {
        return { shifts: [], fallbackUserId: '' };
    }

    if (typeof rules !== 'object' || Array.isArray(rules)) {
        return null;
    }

    const record = rules as Record<string, unknown>;

    if (Object.keys(record).some((key) => !ON_CALL_ROOT_KEYS.has(key))) {
        return null;
    }

    const rawShifts = record.on_call ?? [];

    if (!Array.isArray(rawShifts)) {
        return null;
    }

    const shifts: OnCallShiftDraft[] = [];

    for (const raw of rawShifts) {
        if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) {
            return null;
        }

        const shift = raw as Record<string, unknown>;

        if (Object.keys(shift).some((key) => !ON_CALL_SHIFT_KEYS.has(key))) {
            return null;
        }

        shifts.push({
            id: ++onCallDraftId,
            userId: shift.user_id === undefined ? '' : String(shift.user_id),
            days: Array.isArray(shift.days)
                ? shift.days.map((day) => String(day).toLowerCase())
                : [],
            start: typeof shift.start === 'string' ? shift.start : '',
            end: typeof shift.end === 'string' ? shift.end : '',
        });
    }

    const fallback = record.fallback_on_call_user_id;

    return {
        shifts,
        fallbackUserId:
            fallback === undefined || fallback === null ? '' : String(fallback),
    };
}

function serializeOnCall(draft: OnCallDraft): Record<string, unknown> {
    const onCall = draft.shifts
        .filter((shift) => shift.userId !== '')
        .map((shift) => {
            const out: Record<string, unknown> = {
                user_id: Number(shift.userId),
            };

            if (shift.days.length > 0 && shift.days.length < 7) {
                out.days = shift.days;
            }

            if (shift.start !== '' && shift.end !== '') {
                out.start = shift.start;
                out.end = shift.end;
            }

            return out;
        });

    const result: Record<string, unknown> = { on_call: onCall };

    if (draft.fallbackUserId !== '') {
        result.fallback_on_call_user_id = Number(draft.fallbackUserId);
    }

    return result;
}

export function OnCallSection({
    profiles,
    users,
    canManage,
}: {
    profiles: ScheduleProfileRow[];
    users: RecipientOptions['users'];
    canManage: boolean;
}) {
    const teamSlug = usePage().props.currentTeam?.slug ?? null;
    const [saving, setSaving] = useState(false);

    if (profiles.length === 0) {
        return (
            <SettingsSection
                title="Guardias"
                description="Quién recibe los incidentes nuevos según el día y la hora."
            >
                <FormCard>
                    <EmptyState
                        className="py-8"
                        icon={CalendarClock}
                        title="Aún no hay horario de guardias"
                        description="Mientras tanto, los incidentes se asignan al primer administrador del equipo. El horario se crea al conectar tu proveedor de telemetría; si no aparece, escribe a soporte."
                    />
                </FormCard>
            </SettingsSection>
        );
    }

    const save = async (
        profile: ScheduleProfileRow,
        shiftRules: Record<string, unknown> | unknown[] | null,
        timezone: string,
    ) => {
        if (teamSlug === null || shiftRules === null) {
            return;
        }

        setSaving(true);
        await submit(
            putJson(
                tenantConfigRoutes.schedule.update.url([teamSlug, profile.id]),
                {
                    timezone,
                    shift_rules: shiftRules,
                },
            ),
            'Guardias guardadas.',
            CONFIG_SUBMIT,
        );
        setSaving(false);
    };

    return (
        <>
            {profiles.map((profile) => (
                <ScheduleCard
                    key={profile.id}
                    profile={profile}
                    users={users}
                    canManage={canManage}
                    saving={saving}
                    onSave={save}
                />
            ))}
        </>
    );
}

function ScheduleCard({
    profile,
    users,
    canManage,
    saving,
    onSave,
}: {
    profile: ScheduleProfileRow;
    users: RecipientOptions['users'];
    canManage: boolean;
    saving: boolean;
    onSave: (
        profile: ScheduleProfileRow,
        shiftRules: Record<string, unknown> | unknown[] | null,
        timezone: string,
    ) => Promise<void>;
}) {
    const [timezone, setTimezone] = useState(profile.timezone);
    const [draft, setDraft] = useState<OnCallDraft | null>(() =>
        parseOnCall(profile.shiftRules),
    );
    const [rawShifts, setRawShifts] = useState(
        JSON.stringify(profile.shiftRules ?? [], null, 2),
    );
    const [confirmReset, setConfirmReset] = useState(false);
    // Lista de turnos sin persona (formato antiguo): el asignador de guardias
    // sólo lee `{on_call: [...]}`, así que no asigna a nadie.
    const legacyShiftList = Array.isArray(profile.shiftRules);
    const title =
        profile.profileCode === 'default'
            ? 'Horario principal'
            : humanizeCode(profile.profileCode);

    const timezones = TIMEZONES.some((tz) => tz.value === timezone)
        ? TIMEZONES
        : [{ value: timezone, label: timezone }, ...TIMEZONES];

    const save = () => {
        if (draft !== null) {
            void onSave(profile, serializeOnCall(draft), timezone);

            return;
        }

        void onSave(
            profile,
            parseJson(rawShifts, `las guardias de «${title}»`),
            timezone,
        );
    };

    return (
        <SettingsSection
            title={title}
            description="Cuando llega un incidente, se asigna a quien esté de guardia en ese momento."
        >
            <FormCard className="gap-5">
                <Field
                    label="Zona horaria"
                    help="Las horas de las guardias se leen en esta zona."
                    htmlFor={`tz-${profile.id}`}
                >
                    <Select
                        value={timezone}
                        disabled={!canManage}
                        onValueChange={setTimezone}
                    >
                        <SelectTrigger
                            id={`tz-${profile.id}`}
                            className="h-9 w-full sm:w-80"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {timezones.map((tz) => (
                                <SelectItem key={tz.value} value={tz.value}>
                                    {tz.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>

                {draft !== null ? (
                    <OnCallEditor
                        draft={draft}
                        users={users}
                        disabled={!canManage}
                        onChange={setDraft}
                    />
                ) : (
                    <>
                        <div className="flex flex-col gap-2 rounded-md border border-severity-medium/40 bg-severity-medium/10 p-3 text-xs text-fg-2">
                            {legacyShiftList ? (
                                <p>
                                    <span className="font-semibold text-fg-1">
                                        Estos turnos no dicen quién está de
                                        guardia.
                                    </span>{' '}
                                    SAM sólo asigna incidentes a guardias con
                                    una persona, así que hoy todo va a la
                                    persona de respaldo. Puedes reemplazarlos
                                    por guardias con el editor.
                                </p>
                            ) : (
                                <p>
                                    Este horario usa opciones que el editor no
                                    muestra. Puedes editarlo como texto (no se
                                    pierde ningún dato) o empezar de nuevo con
                                    el editor de guardias.
                                </p>
                            )}
                            {canManage ? (
                                <div>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setConfirmReset(true)}
                                    >
                                        Usar el editor de guardias
                                    </Button>
                                </div>
                            ) : null}
                        </div>
                        <Field
                            label="Turnos actuales (formato avanzado)"
                            help="Se conservan tal cual mientras no los reemplaces."
                            htmlFor={`raw-${profile.id}`}
                        >
                            <JsonField
                                id={`raw-${profile.id}`}
                                value={rawShifts}
                                onChange={setRawShifts}
                                disabled={!canManage}
                            />
                        </Field>
                        <ConfirmDialog
                            open={confirmReset}
                            title="¿Reemplazar los turnos actuales?"
                            description="Se descartan los turnos en formato avanzado y empiezas con el editor de guardias vacío. No se guarda nada hasta que pulses «Guardar guardias»."
                            confirmLabel="Reemplazar"
                            onConfirm={() => {
                                setDraft({ shifts: [], fallbackUserId: '' });
                                setConfirmReset(false);
                            }}
                            onOpenChange={(open) =>
                                !open && setConfirmReset(false)
                            }
                        />
                    </>
                )}

                {canManage ? (
                    <div className="-mx-5 -mb-5 flex justify-end rounded-b-lg border-t border-border bg-surface-2 px-5 py-3">
                        <Button size="sm" onClick={save} disabled={saving}>
                            Guardar guardias
                        </Button>
                    </div>
                ) : null}
            </FormCard>
        </SettingsSection>
    );
}

const NO_USER = '__none__';

function OnCallEditor({
    draft,
    users,
    disabled,
    onChange,
}: {
    draft: OnCallDraft;
    users: RecipientOptions['users'];
    disabled: boolean;
    onChange: (draft: OnCallDraft) => void;
}) {
    const replace = (index: number, shift: OnCallShiftDraft) => {
        const shifts = [...draft.shifts];
        shifts[index] = shift;
        onChange({ ...draft, shifts });
    };

    const toggleDay = (index: number, day: string) => {
        const shift = draft.shifts[index];

        replace(index, {
            ...shift,
            days: shift.days.includes(day)
                ? shift.days.filter((d) => d !== day)
                : [...shift.days, day],
        });
    };

    const addShift = () =>
        onChange({
            ...draft,
            shifts: [
                ...draft.shifts,
                {
                    id: ++onCallDraftId,
                    userId: '',
                    days: [],
                    start: '08:00',
                    end: '20:00',
                },
            ],
        });

    const userSelect = (
        id: string,
        value: string,
        onValue: (value: string) => void,
        emptyLabel: string,
        label: string,
    ) => (
        <Select
            value={value === '' ? NO_USER : value}
            disabled={disabled}
            onValueChange={(next) => onValue(next === NO_USER ? '' : next)}
        >
            <SelectTrigger
                id={id}
                aria-label={label}
                className="h-9 w-full sm:w-64"
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NO_USER}>{emptyLabel}</SelectItem>
                {users.map((user) => (
                    <SelectItem key={user.value} value={user.value}>
                        {user.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );

    return (
        <>
            <div className="flex flex-col gap-2">
                <div>
                    <h3 className="text-sm font-semibold text-fg-1">Turnos</h3>
                    <p className="text-2xs text-fg-3">
                        Se usa el primer turno que coincide con el día y la
                        hora. Sin días marcados, vale para toda la semana; un
                        turno de 22:00 a 06:00 cruza la medianoche sin problema.
                    </p>
                </div>
                {draft.shifts.length === 0 ? (
                    <p className="rounded-md border border-dashed border-border px-3 py-4 text-center text-xs text-fg-3">
                        Sin turnos: todos los incidentes van a la persona de
                        respaldo.
                    </p>
                ) : null}
                {draft.shifts.map((shift, index) => (
                    <div
                        key={shift.id}
                        className="flex flex-wrap items-center gap-3 rounded-md border border-border bg-surface-2 p-3"
                    >
                        {userSelect(
                            `oncall-${shift.id}-user`,
                            shift.userId,
                            (userId) => replace(index, { ...shift, userId }),
                            'Elige a la persona',
                            `Persona del turno ${index + 1}`,
                        )}
                        <div
                            className="flex items-center gap-1"
                            role="group"
                            aria-label="Días"
                        >
                            {WEEKDAYS.map((day) => (
                                <ChipToggle
                                    key={day.value}
                                    active={shift.days.includes(day.value)}
                                    disabled={disabled}
                                    label={day.label}
                                    onToggle={() => toggleDay(index, day.value)}
                                    className="size-8 px-0"
                                >
                                    {day.short}
                                </ChipToggle>
                            ))}
                        </div>
                        <div className="flex items-center gap-1.5 text-xs text-fg-2">
                            <span>De</span>
                            <Input
                                type="time"
                                aria-label="Hora de inicio"
                                value={shift.start}
                                disabled={disabled}
                                onChange={(e) =>
                                    replace(index, {
                                        ...shift,
                                        start: e.target.value,
                                    })
                                }
                                className="h-8 w-28 tabular-nums"
                            />
                            <span>a</span>
                            <Input
                                type="time"
                                aria-label="Hora de fin"
                                value={shift.end}
                                disabled={disabled}
                                onChange={(e) =>
                                    replace(index, {
                                        ...shift,
                                        end: e.target.value,
                                    })
                                }
                                className="h-8 w-28 tabular-nums"
                            />
                        </div>
                        {!disabled ? (
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="ml-auto size-8 text-fg-3 hover:text-severity-critical"
                                aria-label={`Quitar turno ${index + 1}`}
                                onClick={() =>
                                    onChange({
                                        ...draft,
                                        shifts: draft.shifts.filter(
                                            (_, i) => i !== index,
                                        ),
                                    })
                                }
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        ) : null}
                    </div>
                ))}
                {!disabled ? (
                    <div>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={addShift}
                        >
                            <Plus className="size-3.5" /> Añadir turno
                        </Button>
                    </div>
                ) : null}
            </div>

            <Field
                label="Persona de respaldo"
                help="Recibe los incidentes que caen fuera de cualquier turno."
                htmlFor="oncall-fallback"
            >
                {userSelect(
                    'oncall-fallback',
                    draft.fallbackUserId,
                    (fallbackUserId) => onChange({ ...draft, fallbackUserId }),
                    'Primer administrador del equipo',
                    'Persona de respaldo',
                )}
            </Field>
        </>
    );
}
