import { useState } from 'react';
import InputError from '@/components/input-error';
import { ConditionBuilder } from '@/components/sam/condition-builder';
import { FormField } from '@/components/sam/form-field';
import { Step } from '@/components/sam/step';
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
import { priorityLabel } from '@/lib/labels';
import { postJson, putJson } from '@/lib/sam-fetch';
import { submitRuleChange, useRulesBase } from './lib';
import { RuleTester } from './rule-tester';
import type { MappingOptions, MappingRuleRow } from './types';

const FROM_TYPE = 'from-type';

interface MappingRuleSheetProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** null = traducción nueva. */
    rule: MappingRuleRow | null;
    options: MappingOptions;
}

/**
 * Editor de una traducción de alerta (sólo operadores de SAM): qué alerta
 * del proveedor llega y cómo la trata SAM.
 */
export function MappingRuleSheet(props: MappingRuleSheetProps) {
    return (
        <Sheet open={props.open} onOpenChange={props.onOpenChange}>
            <SheetContent className="w-full gap-0 p-0 sm:max-w-xl">
                {props.open && (
                    <MappingRuleForm key={props.rule?.id ?? 'new'} {...props} />
                )}
            </SheetContent>
        </Sheet>
    );
}

function MappingRuleForm({
    onOpenChange,
    rule,
    options,
}: MappingRuleSheetProps) {
    const base = useRulesBase();
    const isNew = rule === null;

    const [providerId, setProviderId] = useState(
        rule ? String(rule.providerId) : (options.providers[0]?.value ?? ''),
    );
    const [externalEventType, setExternalEventType] = useState(
        rule?.externalEventType ?? '',
    );
    const [conditions, setConditions] = useState<Record<string, unknown>>(
        rule?.conditions ?? {},
    );
    const [eventTypeId, setEventTypeId] = useState(
        rule ? String(rule.mappedEventTypeId) : '',
    );
    const [severityId, setSeverityId] = useState<string>(
        rule?.mappedSeverityId != null
            ? String(rule.mappedSeverityId)
            : FROM_TYPE,
    );
    const [priority, setPriority] = useState(String(rule?.priority ?? 100));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const hasConditions = Object.keys(conditions).length > 0;

    const save = async () => {
        if (base === null || saving) {
            return;
        }

        const missing: Record<string, string> = {};

        if (providerId === '') {
            missing.provider_id = 'Elige el proveedor.';
        }

        if (externalEventType.trim() === '') {
            missing.external_event_type = 'Escribe el nombre de la alerta.';
        }

        if (eventTypeId === '') {
            missing.mapped_event_type_id = 'Elige cómo la trata SAM.';
        }

        if (Object.keys(missing).length > 0) {
            setErrors(missing);

            return;
        }

        setErrors({});
        setSaving(true);

        const body = {
            external_event_type: externalEventType.trim(),
            external_conditions_json: hasConditions ? conditions : null,
            mapped_event_type_id: Number(eventTypeId),
            mapped_severity_id:
                severityId === FROM_TYPE ? null : Number(severityId),
            priority: Number(priority) || 0,
        };

        const result = isNew
            ? await submitRuleChange(
                  postJson(`${base}/mapping`, {
                      ...body,
                      provider_id: Number(providerId),
                      is_active: true,
                  }),
                  'Traducción creada.',
              )
            : await submitRuleChange(
                  putJson(`${base}/mapping/${rule.id}`, body),
                  'Traducción guardada.',
              );

        setSaving(false);

        if (result.ok) {
            onOpenChange(false);
        } else {
            setErrors(result.fieldErrors);
        }
    };

    return (
        <>
            <SheetHeader className="shrink-0 border-b border-border px-5 py-4 pr-12">
                <SheetTitle className="text-base">
                    {isNew ? 'Nueva traducción de alerta' : 'Editar traducción'}
                </SheetTitle>
                <SheetDescription className="text-xs">
                    Aplica a todas las cuentas: define cómo entiende SAM una
                    alerta del proveedor.
                </SheetDescription>
            </SheetHeader>

            <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto px-5 py-5">
                <Step step={1} title="¿Qué alerta llega?">
                    <FormField
                        label="Proveedor"
                        htmlFor="mapping-provider"
                        error={errors.provider_id}
                        size="sm"
                    >
                        <Select
                            value={providerId}
                            onValueChange={setProviderId}
                            disabled={!isNew}
                        >
                            <SelectTrigger
                                id="mapping-provider"
                                className="h-9 w-full sm:w-64"
                            >
                                <SelectValue placeholder="Elige proveedor…" />
                            </SelectTrigger>
                            <SelectContent>
                                {options.providers.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        label="Nombre de la alerta en el proveedor"
                        htmlFor="mapping-external"
                        error={errors.external_event_type}
                        size="sm"
                    >
                        <Input
                            id="mapping-external"
                            value={externalEventType}
                            placeholder="Ej. HeavySpeeding"
                            className="font-mono text-xs"
                            onChange={(e) =>
                                setExternalEventType(e.target.value)
                            }
                        />
                        <p className="text-xs text-fg-3">
                            Tal como lo envía el proveedor (en Samsara, el
                            «behaviorLabel» o el tipo de alerta).
                        </p>
                    </FormField>
                    <div className="flex flex-col gap-1.5">
                        <span className="text-xs font-medium text-fg-1">
                            Solo si la alerta trae estos datos{' '}
                            <span className="font-normal text-fg-3">
                                (opcional)
                            </span>
                        </span>
                        <p className="text-xs text-fg-3">
                            Para alertas genéricas como AlertIncident, indica el
                            dato que las distingue. Ej.{' '}
                            <span className="font-mono">
                                data.conditions.0.description
                            </span>{' '}
                            = Panic Button.
                        </p>
                        <ConditionBuilder
                            variant="flat-equality"
                            fields={[]}
                            allowUnknownFields
                            value={conditions}
                            onChange={setConditions}
                        />
                        <InputError
                            message={errors.external_conditions_json}
                            className="text-xs"
                        />
                    </div>
                </Step>

                <Step
                    step={2}
                    title="¿Cómo la trata SAM?"
                    help="El tipo de evento decide qué reglas le aplican y cómo se muestra en la bandeja."
                >
                    <FormField
                        label="Se trata como"
                        htmlFor="mapping-type"
                        error={errors.mapped_event_type_id}
                        size="sm"
                    >
                        <Select
                            value={eventTypeId}
                            onValueChange={setEventTypeId}
                        >
                            <SelectTrigger
                                id="mapping-type"
                                className="h-9 w-full sm:w-72"
                            >
                                <SelectValue placeholder="Elige un tipo de evento…" />
                            </SelectTrigger>
                            <SelectContent>
                                {options.eventTypes.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        label="Gravedad"
                        htmlFor="mapping-severity"
                        size="sm"
                    >
                        <Select
                            value={severityId}
                            onValueChange={setSeverityId}
                        >
                            <SelectTrigger
                                id="mapping-severity"
                                className="h-9 w-full sm:w-72"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={FROM_TYPE}>
                                    La del tipo de evento
                                </SelectItem>
                                {options.severities.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {priorityLabel(
                                            option.label.toLowerCase(),
                                        )}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                </Step>

                {base !== null && hasConditions && (
                    <Step
                        step={3}
                        title="Pruébala"
                        help="Comprueba si la última alerta que recibió tu cuenta trae esos datos."
                    >
                        <RuleTester
                            endpoint={`${base}/test-mapping`}
                            payload={() => ({
                                external_conditions_json: conditions,
                            })}
                            fields={[]}
                            subject="alert"
                        />
                    </Step>
                )}

                <details className="text-xs text-fg-3">
                    <summary className="cursor-pointer select-none hover:text-fg-2">
                        Opciones avanzadas
                    </summary>
                    <div className="mt-2 flex flex-col gap-1.5">
                        <Label htmlFor="mapping-priority" className="text-xs">
                            Prioridad (0–255)
                        </Label>
                        <Input
                            id="mapping-priority"
                            type="number"
                            min={0}
                            max={255}
                            value={priority}
                            onChange={(e) => setPriority(e.target.value)}
                            className="w-28"
                        />
                        <p>
                            Si varias traducciones encajan con la misma alerta,
                            gana la de número más alto.
                        </p>
                        <InputError
                            message={errors.priority}
                            className="text-xs"
                        />
                    </div>
                </details>
            </div>

            <SheetFooter className="shrink-0 flex-row justify-end border-t border-border px-5 py-3">
                <Button variant="ghost" onClick={() => onOpenChange(false)}>
                    Cancelar
                </Button>
                <Button onClick={save} disabled={saving}>
                    {saving
                        ? 'Guardando…'
                        : isNew
                          ? 'Crear traducción'
                          : 'Guardar cambios'}
                </Button>
            </SheetFooter>
        </>
    );
}
